<?php

namespace Opencart\Admin\Controller\Extension\Boxberry\Shipping;

use Boxberry\Client\Client;

if (!class_exists('Client')) {
    require_once DIR_EXTENSION . 'boxberry/system/library/boxberry/autoload.php';
}

/**
 * Class Boxberry
 *
 * @package Opencart\Admin\Controller\Extension\Boxberry\Shipping
 */
class Boxberry extends \Opencart\System\Engine\Controller
{
    /**
     * @return void
     */
    public function install(): void
    {
        $this->load->model('user/user_group');

        $this->model_user_user_group->addPermission($this->user->getGroupId(), 'access', 'sale/boxberry');

        $this->load->model('setting/event');

        $this->model_setting_event->addEvent([
            'code'        => 'boxberry_order_status_edit',
            'description' => 'Создаёт заказ в личном кабинете Boxberry',
            'trigger'     => 'catalog/model/checkout/order/addOrderHistory/after',
            'action'      => 'event/boxberry/addOrderHistory',
            'status'      => '1',
            'sort_order'  => '1'
        ]);

        $this->model_setting_event->addEvent([
            'code'        => 'boxberry_add_scripts',
            'description' => 'Подключает скрипты для виджета Boxberry',
            'trigger'     => 'catalog/controller/common/header/before',
            'action'      => 'event/boxberry/addScripts',
            'status'      => '1',
            'sort_order'  => '1'
        ]);

        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "boxberry_cities` (
      `code` VARCHAR(128) NOT NULL,
      `name` VARCHAR(128),
      `region` VARCHAR(128),
      `data` text,
      PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE utf8_general_ci;");

        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "boxberry_deliveries` (
      `order_id` int(10) NOT NULL,
      `im_id` VARCHAR(255),
      `label` VARCHAR(255),
      `boxberry_to_point` VARCHAR(15),
      `address` VARCHAR(255),
      `error` VARCHAR(255),
      PRIMARY KEY (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE utf8_general_ci;");

        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "boxberry_points` (
      `code` VARCHAR(128) NOT NULL,
      `city_code` VARCHAR(128),
      `data` text,
      `expired` datetime,
      `prepaid` tinyint(1),
      PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE utf8_general_ci;");

        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "boxberry_listzips` (
      `zip` VARCHAR(128) NOT NULL,
      `city` VARCHAR(128),
      `area` VARCHAR(128),
      PRIMARY KEY (`zip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE utf8_general_ci;");

        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "boxberry_expired` (
      `table` VARCHAR(128) NOT NULL,
      `expired` datetime,
      PRIMARY KEY (`table`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE utf8_general_ci;");
    }

    /**
     * @return void
     */
    public function uninstall(): void
    {
        $this->load->model('user/user_group');
        $this->model_user_user_group->removePermission($this->user->getGroupId(), 'access', 'sale/boxberry');

        $this->load->model('setting/event');
        $this->model_setting_event->deleteEventByCode('boxberry_order_status_edit');
        $this->model_setting_event->deleteEventByCode('boxberry_add_scripts');

        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "boxberry_cities`;");
        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "boxberry_deliveries`;");
        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "boxberry_points`;");
        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "boxberry_listzips`;");
        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "boxberry_expired`;");
    }

    /**
     * @return void
     */
    public function index(): void
    {
        $this->load->language('extension/boxberry/shipping/boxberry');

        $this->document->setTitle($this->language->get('heading_title'));

        $data['breadcrumbs'] = [];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])
        ];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=shipping')
        ];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/boxberry/shipping/boxberry', 'user_token=' . $this->session->data['user_token'])
        ];

        $data['save'] = $this->url->link('extension/boxberry/shipping/boxberry.save', 'user_token=' . $this->session->data['user_token']);
        $data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=shipping');

        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        $this->load->model('localisation/weight_class');
        $data['weight_ids'] = $this->model_localisation_weight_class->getWeightClasses();

        $this->load->model('localisation/length_class');
        $data['length_ids'] = $this->model_localisation_length_class->getLengthClasses();

        /*$this->load->model('localisation/weight_class');
        if (($weight = $this->model_localisation_weight_class->getWeightClassDescriptionByUnit('g'))
            || ($weight = $this->model_localisation_weight_class->getWeightClassDescriptionByUnit('г'))) {
            $this->request->post['shipping_boxberry_weight_class_id'] = $weight['weight_class_id'];
        }

        $this->load->model('localisation/length_class');
        if (($length = $this->model_localisation_length_class->getLengthClassDescriptionByUnit('cm'))
            || ($length = $this->model_localisation_length_class->getLengthClassDescriptionByUnit('см'))) {
            $this->request->post['shipping_boxberry_length_class_id'] = $length['length_class_id'];
        }*/

        $fields = [
            [
                'name' => 'shipping_boxberry_status',
                'default' => '0'
            ],
            [
                'name' => 'shipping_boxberry_api_url',
                'default' => 'https://api.boxberry.ru/json.php'
            ],
            [
                'name' => 'shipping_boxberry_widget_url',
                'default' => 'https://points.boxberry.de/js/boxberry.js'
            ],
            [
                'name' => 'shipping_boxberry_api_token',
                'default' => ''
            ],
            [
                'name' => 'shipping_boxberry_order_status',
                'default' => ''
            ],
            [
                'name' => 'shipping_boxberry_sort_order',
                'default' => ''
            ],
            [
                'name' => 'shipping_boxberry_weight',
                'default' => '500'
            ],
            [
                'name' => 'shipping_boxberry_weight_min',
                'default' => '5'
            ],
            [
                'name' => 'shipping_boxberry_weight_max',
                'default' => '31000'
            ],
            [
                'name' => 'shipping_boxberry_package_size',
                'default' => ''
            ],
            [
                'name' => 'shipping_boxberry_width',
                'default' => ''
            ],
            [
                'name' => 'shipping_boxberry_height',
                'default' => ''
            ],
            [
                'name' => 'shipping_boxberry_depth',
                'default' => ''
            ],
            [
                'name' => 'shipping_boxberry_weight_class_id',
                'default' => ''
            ],
            [
                'name' => 'shipping_boxberry_length_class_id',
                'default' => ''
            ],
            [
                'name' => 'shipping_boxberry_pickup_status',
                'default' => '0'
            ],
            [
                'name' => 'shipping_boxberry_pickup_prepaid_status',
                'default' => '0'
            ],
            [
                'name' => 'shipping_boxberry_courier_delivery_status',
                'default' => '0'
            ],
            [
                'name' => 'shipping_boxberry_courier_delivery_prepaid_status',
                'default' => '0'
            ],
            [
                'name' => 'shipping_boxberry_pickup_sucrh',
                'default' => '0'
            ],
            [
                'name' => 'shipping_boxberry_pickup_prepaid_sucrh',
                'default' => '0'
            ],
            [
                'name' => 'shipping_boxberry_courier_delivery_sucrh',
                'default' => '0'
            ],
            [
                'name' => 'shipping_boxberry_courier_delivery_prepaid_sucrh',
                'default' => '0'
            ],
            [
                'name' => 'shipping_boxberry_pickup_parselcreate_auto',
                'default' => '0'
            ],
            [
                'name' => 'shipping_boxberry_pickup_prepaid_parselcreate_auto',
                'default' => '0'
            ],
            [
                'name' => 'shipping_boxberry_courier_delivery_parselcreate_auto',
                'default' => '0'
            ],
            [
                'name' => 'shipping_boxberry_courier_delivery_prepaid_parselcreate_auto',
                'default' => '0'
            ],
            [
                'name' => 'shipping_boxberry_pickup_parselsend_auto',
                'default' => '0'
            ],
            [
                'name' => 'shipping_boxberry_pickup_prepaid_parselsend_auto',
                'default' => '0'
            ],
            [
                'name' => 'shipping_boxberry_courier_delivery_parselsend_auto',
                'default' => '0'
            ],
            [
                'name' => 'shipping_boxberry_courier_delivery_prepaid_parselsend_auto',
                'default' => '0'
            ],
            [
                'name' => 'shipping_boxberry_pickup_name',
                'default' => $this->language->get('text_pickup_description')
            ],
            [
                'name' => 'shipping_boxberry_pickup_prepaid_name',
                'default' => $this->language->get('text_pickup_prepaid_description')
            ],
            [
                'name' => 'shipping_boxberry_courier_delivery_name',
                'default' => $this->language->get('text_courier_delivery_description')
            ],
            [
                'name' => 'shipping_boxberry_courier_delivery_prepaid_name',
                'default' => $this->language->get('text_courier_delivery_prepaid_description')
            ]
        ];

        foreach ($fields as $field) {
            if ($this->config->get($field['name']) !== '') {
                $data[$field['name']] = $this->config->get($field['name']);
            } elseif ($field['default'] !== '') {
                $data[$field['name']] = $field['default'];
            } else {
                $data[$field['name']] = '';
            }
        }

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/boxberry/shipping/boxberry', $data));
    }

    /**
     * @return void
     */
    public function save(): void
    {
        $this->load->language('extension/boxberry/shipping/boxberry');

        $json = [];

        if (!$this->user->hasPermission('modify', 'extension/boxberry/shipping/boxberry')) {
            $json['error'] = $this->language->get('error_permission');
        }
        if (!$this->request->post['shipping_boxberry_api_token']) {
            $json['error'] = $this->language->get('error_api_token');
        }
        if (!$this->request->post['shipping_boxberry_api_url']) {
            $json['error'] = $this->language->get('error_api_url');
        }
        if (!$this->request->post['shipping_boxberry_weight']) {
            $json['error'] = $this->language->get('error_weight');
        }
        if (!$this->request->post['shipping_boxberry_widget_url']) {
            $json['error'] = $this->language->get('error_widget_url');
        }

        $client = new Client();
        $client->setApiToken($this->request->post['shipping_boxberry_api_token']);
        $client->setApiUrl($this->request->post['shipping_boxberry_api_url']);
        $getKeyIntegrationRequest = $client->getKeyIntegration();
        try {
            $response = $client->execute($getKeyIntegrationRequest);
            $this->request->post['shipping_boxberry_widget_key'] = $response->getKeyIntegration();
        } catch (\Exception $e) {
            if ($e->getMessage() === 'Ошибка обращения к сервису доставки Boxberry') {
                $json['error'] = $this->language->get('error_wrong_api_url');
            } elseif ($e->getMessage() === 'Нет доступа') {
                $json['error'] = $this->language->get('error_wrong_api_token');
            } elseif ($e->getMessage() === 'Ваша учетная запись заблокирована') {
                $json['error'] = $this->language->get('error_blocked_api_token');
            }
        }

        if (!$json) {
            $this->load->model('setting/setting');

            $this->model_setting_setting->editSetting('shipping_boxberry', $this->request->post);

            $json['success'] = $this->language->get('text_success');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }
}
