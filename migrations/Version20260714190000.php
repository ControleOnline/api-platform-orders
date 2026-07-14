<?php

declare(strict_types=1);

namespace DoctrineMigrations\Orders;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260714190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Baseline schema for orders module from s.controleonline.com";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('SET FOREIGN_KEY_CHECKS=0');
        $this->addSql('CREATE TABLE IF NOT EXISTS `order_file` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `file_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_file_unique` (`order_id`,`file_id`),
  KEY `order_file_order_id_idx` (`order_id`),
  KEY `order_file_file_id_idx` (`file_id`),
  CONSTRAINT `FK_ORDER_FILE_FILE` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_ORDER_FILE_ORDER` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=94 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->addSql('CREATE TABLE IF NOT EXISTS `order_invoice` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `real_price` decimal(15,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_id` (`order_id`,`invoice_id`) USING BTREE,
  KEY `invoice_id` (`invoice_id`),
  CONSTRAINT `order_invoice_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `order_invoice_ibfk_2` FOREIGN KEY (`invoice_id`) REFERENCES `invoice` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=36210 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->addSql('CREATE TABLE IF NOT EXISTS `order_invoice_tax` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `invoice_tax_id` int(11) NOT NULL,
  `invoice_type` int(11) NOT NULL,
  `issuer_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_id` (`order_id`,`invoice_tax_id`) USING BTREE,
  UNIQUE KEY `order_id_2` (`issuer_id`,`invoice_type`,`order_id`) USING BTREE,
  KEY `invoice_tax_id` (`invoice_tax_id`) USING BTREE,
  CONSTRAINT `order_invoice_tax_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `order_invoice_tax_ibfk_2` FOREIGN KEY (`invoice_tax_id`) REFERENCES `invoice_tax` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `order_invoice_tax_ibfk_3` FOREIGN KEY (`issuer_id`) REFERENCES `people` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->addSql('CREATE TABLE IF NOT EXISTS `order_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `alter_type` enum(\'Value\',\'Status\',\'Document\') CHARACTER SET utf8 NOT NULL,
  `order_id` int(11) NOT NULL,
  `people_id` int(11) NOT NULL,
  `alter_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `note` text CHARACTER SET utf8 NOT NULL,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `people_id` (`people_id`),
  CONSTRAINT `order_log_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `order_log_ibfk_2` FOREIGN KEY (`people_id`) REFERENCES `people` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->addSql('CREATE TABLE IF NOT EXISTS `order_logistic` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `origin_provider_id` int(11) DEFAULT NULL,
  `order_id` int(11) NOT NULL,
  `status_id` int(11) NOT NULL,
  `estimated_shipping_date` date DEFAULT NULL,
  `shipping_date` date DEFAULT NULL,
  `estimated_arrival_date` date DEFAULT NULL,
  `arrival_date` date DEFAULT NULL,
  `origin_type` int(11) DEFAULT NULL,
  `origin_city_id` int(100) DEFAULT NULL,
  `origin_address` varchar(150) CHARACTER SET utf8 DEFAULT NULL,
  `destination_type` int(11) DEFAULT NULL,
  `destination_city_id` int(100) DEFAULT NULL,
  `destination_address` varchar(150) CHARACTER SET utf8 DEFAULT NULL,
  `destination_provider_id` int(11) DEFAULT NULL,
  `price` float NOT NULL DEFAULT \'0\',
  `amount_paid` float NOT NULL DEFAULT \'0\',
  `balance` float NOT NULL DEFAULT \'0\',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_modified` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `provider_id` (`origin_provider_id`),
  KEY `status_id` (`status_id`),
  KEY `order_logistic__ibfk_4` (`destination_provider_id`),
  KEY `order_logistic__ibfk_5` (`created_by`),
  KEY `destination_type` (`destination_type`),
  KEY `origin_type` (`origin_type`),
  KEY `origin_city_id` (`origin_city_id`),
  KEY `destination_city_id` (`destination_city_id`),
  CONSTRAINT `order_logistic_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `order_logistic_ibfk_10` FOREIGN KEY (`origin_city_id`) REFERENCES `city` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `order_logistic_ibfk_11` FOREIGN KEY (`destination_city_id`) REFERENCES `city` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `order_logistic_ibfk_2` FOREIGN KEY (`origin_provider_id`) REFERENCES `people` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `order_logistic_ibfk_3` FOREIGN KEY (`status_id`) REFERENCES `status` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `order_logistic_ibfk_4` FOREIGN KEY (`destination_provider_id`) REFERENCES `people` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `order_logistic_ibfk_7` FOREIGN KEY (`origin_provider_id`) REFERENCES `people` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `order_logistic_ibfk_8` FOREIGN KEY (`destination_type`) REFERENCES `category` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `order_logistic_ibfk_9` FOREIGN KEY (`origin_type`) REFERENCES `category` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8194 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->addSql('CREATE TABLE IF NOT EXISTS `order_logistic_surveys` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `token_url` binary(7) NOT NULL,
  `order_logistic_id` int(11) DEFAULT NULL,
  `professional_id` int(11) DEFAULT NULL,
  `address_id` int(11) DEFAULT NULL,
  `surveyor_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL,
  `type_survey` enum(\'collect\',\'delivery\',\'others\') CHARACTER SET utf8 DEFAULT NULL,
  `other_informations` text CHARACTER SET utf8,
  `belongings_removed` enum(\'no\',\'yes\') CHARACTER SET utf8 DEFAULT NULL,
  `vehicle_km` int(11) DEFAULT NULL,
  `status` enum(\'pending\',\'complete\',\'canceled\') CHARACTER SET utf8 NOT NULL DEFAULT \'pending\',
  `comments` text CHARACTER SET utf8,
  PRIMARY KEY (`id`),
  KEY `status` (`status`),
  KEY `token_url` (`token_url`,`id`),
  KEY `order_logistic_surveys_order_logistic_id_fk` (`order_logistic_id`),
  KEY `tasks_surveys_address_id_fk` (`address_id`),
  KEY `tasks_surveys_people_id_fk` (`professional_id`),
  KEY `tasks_surveys_people_id_fk_2` (`surveyor_id`),
  CONSTRAINT `order_logistic_surveys_order_logistic_id_fk` FOREIGN KEY (`order_logistic_id`) REFERENCES `order_logistic` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `tasks_surveys_address_id_fk` FOREIGN KEY (`address_id`) REFERENCES `address` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `tasks_surveys_people_id_fk` FOREIGN KEY (`professional_id`) REFERENCES `people` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `tasks_surveys_people_id_fk_2` FOREIGN KEY (`surveyor_id`) REFERENCES `people` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->addSql('CREATE TABLE IF NOT EXISTS `order_logistic_surveys_files` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `order_logistic_surveys_id` int(11) DEFAULT NULL,
  `filename` varchar(255) CHARACTER SET utf8 DEFAULT NULL,
  `region` enum(\'front\',\'left_side\',\'right_side\',\'rear\',\'panel\',\'motor\',\'others\') CHARACTER SET utf8 DEFAULT NULL,
  `breakdown` enum(\'none\',\'kneaded\',\'absence\',\'chop\',\'broke\',\'scratched\',\'cracked\') CHARACTER SET utf8 DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tasks_surveys_files_tasks_surveys_id_fk` (`order_logistic_surveys_id`),
  CONSTRAINT `tasks_surveys_files_tasks_surveys_id_fk` FOREIGN KEY (`order_logistic_surveys_id`) REFERENCES `order_logistic_surveys` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->addSql('CREATE TABLE IF NOT EXISTS `order_package` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `qtd` decimal(10,3) DEFAULT NULL,
  `height` decimal(10,2) DEFAULT NULL,
  `width` decimal(10,2) DEFAULT NULL,
  `depth` decimal(10,2) DEFAULT NULL,
  `weight` decimal(10,3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  CONSTRAINT `order_package_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->addSql('CREATE TABLE IF NOT EXISTS `order_product` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `comment` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `in_inventory_id` int(11) DEFAULT NULL,
  `out_inventory_id` int(11) DEFAULT NULL,
  `product_group_id` int(11) DEFAULT NULL,
  `parent_product_id` int(11) DEFAULT NULL,
  `order_product_id` int(11) DEFAULT NULL,
  `quantity` float NOT NULL,
  `price` float NOT NULL,
  `total` float NOT NULL,
  `show_in_parent_queue` tinyint(1) NOT NULL DEFAULT \'1\',
  `status_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `orders_id` (`order_id`),
  KEY `product_id` (`product_id`),
  KEY `parent_product_id` (`parent_product_id`),
  KEY `parent_order_product_id` (`order_product_id`),
  KEY `product_group_id` (`product_group_id`),
  KEY `inventory_id` (`out_inventory_id`),
  KEY `in_inventory_id` (`in_inventory_id`),
  KEY `user_id` (`user_id`),
  KEY `status_id` (`status_id`),
  CONSTRAINT `order_product_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `order_product_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `product` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `order_product_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `order_product_ibfk_4` FOREIGN KEY (`parent_product_id`) REFERENCES `product` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `order_product_ibfk_5` FOREIGN KEY (`order_product_id`) REFERENCES `order_product` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `order_product_ibfk_6` FOREIGN KEY (`product_group_id`) REFERENCES `product_group` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `order_product_ibfk_7` FOREIGN KEY (`out_inventory_id`) REFERENCES `inventory` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `order_product_ibfk_8` FOREIGN KEY (`in_inventory_id`) REFERENCES `inventory` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `order_product_status_id_fk` FOREIGN KEY (`status_id`) REFERENCES `status` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=105763 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->addSql('CREATE TABLE IF NOT EXISTS `order_product_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `queue_id` int(11) DEFAULT NULL,
  `order_product_id` int(11) NOT NULL,
  `priority` enum(\'Default\',\'Priority\',\'Emergency\') CHARACTER SET utf8 NOT NULL,
  `status_id` int(11) NOT NULL,
  `register_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `status_id` (`status_id`),
  KEY `queue_id` (`queue_id`),
  KEY `order_product_id` (`order_product_id`) USING BTREE,
  CONSTRAINT `order_product_queue_ibfk_2` FOREIGN KEY (`status_id`) REFERENCES `status` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `order_product_queue_ibfk_3` FOREIGN KEY (`queue_id`) REFERENCES `queue` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `order_product_queue_ibfk_4` FOREIGN KEY (`order_product_id`) REFERENCES `order_product` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=25016 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->addSql('CREATE TABLE IF NOT EXISTS `order_tracking` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `system_type` varchar(50) CHARACTER SET utf8 NOT NULL,
  `notified` tinyint(4) NOT NULL DEFAULT \'0\',
  `tracking_status` int(11) DEFAULT NULL,
  `data_hora` varchar(50) CHARACTER SET utf8 DEFAULT NULL,
  `dominio` varchar(50) CHARACTER SET utf8 DEFAULT NULL,
  `filial` varchar(50) CHARACTER SET utf8 DEFAULT NULL,
  `cidade` varchar(50) CHARACTER SET utf8 DEFAULT NULL,
  `ocorrencia` varchar(255) CHARACTER SET utf8 DEFAULT NULL,
  `descricao` varchar(255) CHARACTER SET utf8 DEFAULT NULL,
  `tipo` varchar(50) CHARACTER SET utf8 DEFAULT NULL,
  `data_hora_efetiva` varchar(50) CHARACTER SET utf8 DEFAULT NULL,
  `nome_recebedor` varchar(100) CHARACTER SET utf8 DEFAULT NULL,
  `nro_doc_recebedor` varchar(100) CHARACTER SET utf8 DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  CONSTRAINT `order_tracking_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->addSql('CREATE TABLE IF NOT EXISTS `orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `external_code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `order_type` varchar(64) CHARACTER SET utf8 DEFAULT \'quote\',
  `app` text CHARACTER SET utf8,
  `discount_coupon_id` int(11) DEFAULT NULL,
  `main_order_id` int(11) DEFAULT NULL,
  `notified` tinyint(1) NOT NULL DEFAULT \'0\',
  `order_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `alter_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `status_id` int(11) NOT NULL,
  `client_id` int(11) DEFAULT NULL,
  `provider_id` int(11) NOT NULL,
  `retrieve_people_id` int(11) DEFAULT NULL,
  `delivery_people_id` int(11) DEFAULT NULL,
  `payer_people_id` int(11) DEFAULT NULL,
  `quote_id` int(11) DEFAULT NULL,
  `address_origin_id` int(11) DEFAULT NULL,
  `address_destination_id` int(11) DEFAULT NULL,
  `retrieve_contact_id` int(11) DEFAULT NULL,
  `delivery_contact_id` int(11) DEFAULT NULL,
  `comments` text CHARACTER SET utf8,
  `other_informations` longtext CHARACTER SET utf8,
  `price` decimal(15,2) DEFAULT NULL,
  `invoice_total` decimal(15,2) DEFAULT NULL,
  `cubage` decimal(12,4) DEFAULT NULL,
  `product_type` text CHARACTER SET utf8,
  `contract_id` int(11) DEFAULT NULL COMMENT \'APENAS NO BANCO LAVEGO\',
  `estimated_parking_date` timestamp NULL DEFAULT NULL,
  `parking_date` timestamp NULL DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `device_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `discount_id` (`discount_coupon_id`),
  KEY `provider_id` (`provider_id`),
  KEY `adress_origin_id` (`address_origin_id`),
  KEY `adress_destination_id` (`address_destination_id`),
  KEY `retrieve_contact_id` (`retrieve_contact_id`),
  KEY `delivery_contact_id` (`delivery_contact_id`),
  KEY `retrieve_people_id` (`retrieve_people_id`),
  KEY `delivery_people_id` (`delivery_people_id`),
  KEY `payer_people_id` (`payer_people_id`),
  KEY `order_status_id` (`status_id`),
  KEY `client_id` (`client_id`) USING BTREE,
  KEY `order_date` (`order_date`),
  KEY `alter_date` (`alter_date`),
  KEY `quote_id` (`quote_id`,`provider_id`) USING BTREE,
  KEY `notified` (`notified`),
  KEY `main_order_id` (`main_order_id`),
  KEY `user_id` (`user_id`),
  KEY `orders_ibfk_16` (`device_id`),
  CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`quote_id`) REFERENCES `quote` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `orders_ibfk_10` FOREIGN KEY (`address_destination_id`) REFERENCES `address` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `orders_ibfk_11` FOREIGN KEY (`status_id`) REFERENCES `status` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `orders_ibfk_12` FOREIGN KEY (`main_order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `orders_ibfk_13` FOREIGN KEY (`discount_coupon_id`) REFERENCES `discount_coupon` (`id`),
  CONSTRAINT `orders_ibfk_14` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `orders_ibfk_15` FOREIGN KEY (`device_id`) REFERENCES `device` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `orders_ibfk_2` FOREIGN KEY (`client_id`) REFERENCES `people` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `orders_ibfk_3` FOREIGN KEY (`provider_id`) REFERENCES `people` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `orders_ibfk_4` FOREIGN KEY (`retrieve_contact_id`) REFERENCES `people` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `orders_ibfk_5` FOREIGN KEY (`delivery_contact_id`) REFERENCES `people` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `orders_ibfk_6` FOREIGN KEY (`retrieve_people_id`) REFERENCES `people` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `orders_ibfk_7` FOREIGN KEY (`delivery_people_id`) REFERENCES `people` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `orders_ibfk_8` FOREIGN KEY (`payer_people_id`) REFERENCES `people` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `orders_ibfk_9` FOREIGN KEY (`address_origin_id`) REFERENCES `address` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=72651 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->addSql('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('SET FOREIGN_KEY_CHECKS=0');
        $this->addSql('DROP TABLE IF EXISTS `orders`');
        $this->addSql('DROP TABLE IF EXISTS `order_tracking`');
        $this->addSql('DROP TABLE IF EXISTS `order_product_queue`');
        $this->addSql('DROP TABLE IF EXISTS `order_product`');
        $this->addSql('DROP TABLE IF EXISTS `order_package`');
        $this->addSql('DROP TABLE IF EXISTS `order_logistic_surveys_files`');
        $this->addSql('DROP TABLE IF EXISTS `order_logistic_surveys`');
        $this->addSql('DROP TABLE IF EXISTS `order_logistic`');
        $this->addSql('DROP TABLE IF EXISTS `order_log`');
        $this->addSql('DROP TABLE IF EXISTS `order_invoice_tax`');
        $this->addSql('DROP TABLE IF EXISTS `order_invoice`');
        $this->addSql('DROP TABLE IF EXISTS `order_file`');
        $this->addSql('SET FOREIGN_KEY_CHECKS=1');
    }
}
