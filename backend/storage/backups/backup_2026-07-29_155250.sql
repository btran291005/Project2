-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: project2
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `accounts`
--

DROP TABLE IF EXISTS `accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `accounts` (
  `account_id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `role_id` int(11) NOT NULL,
  `status` enum('active','locked') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`account_id`),
  UNIQUE KEY `username` (`username`),
  KEY `role_id` (`role_id`),
  CONSTRAINT `accounts_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `accounts`
--

LOCK TABLES `accounts` WRITE;
/*!40000 ALTER TABLE `accounts` DISABLE KEYS */;
INSERT INTO `accounts` VALUES (1,'admin','$2a$10$x/6I5B/kxGecjcjVxVPSTO5lMvIi7ib8J8dKxvpXxkCNbeDPYZm1O','Trụ sở chính (Admin)','admin@gs25.vn','0900000001',1,'active','2026-07-29 15:36:23'),(2,'manager1','$2a$10$eH6AY24mQt9Zg20J28e/a.HjmaIYzscsSgOfk163OzIKOBFdTfACm','Lê Hà Bảo Trân (Store Manager)','bao.tran@gs25.vn','0901234567',2,'active','2026-07-29 15:36:23'),(3,'manager2','$2a$10$eH6AY24mQt9Zg20J28e/a.HjmaIYzscsSgOfk163OzIKOBFdTfACm','Đỗ Thị Phương (Store Manager)','phuong.do@gs25.vn','0901234568',2,'active','2026-07-29 15:36:23'),(4,'staff1','$2a$10$mvgA2kXGLewpqqubV0dkUeQhL0nZmdJ7cSqaiRRJJn1oVXncvZJ2S','Nguyễn Văn Nam (Staff Sáng)','staff1@gs25.vn','0901111111',3,'active','2026-07-29 15:36:23'),(5,'staff2','$2a$10$mvgA2kXGLewpqqubV0dkUeQhL0nZmdJ7cSqaiRRJJn1oVXncvZJ2S','Trần Thu Hà (Staff Sáng)',NULL,NULL,3,'active','2026-07-29 15:36:23'),(6,'staff3','$2a$10$mvgA2kXGLewpqqubV0dkUeQhL0nZmdJ7cSqaiRRJJn1oVXncvZJ2S','Lê Hoàng Long (Staff Chiều)',NULL,NULL,3,'active','2026-07-29 15:36:23'),(7,'staff4','$2a$10$mvgA2kXGLewpqqubV0dkUeQhL0nZmdJ7cSqaiRRJJn1oVXncvZJ2S','Phạm Minh Đạt (Staff Đêm)',NULL,NULL,3,'active','2026-07-29 15:36:23');
/*!40000 ALTER TABLE `accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `adjustment_reasons`
--

DROP TABLE IF EXISTS `adjustment_reasons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `adjustment_reasons` (
  `reason_id` int(11) NOT NULL AUTO_INCREMENT,
  `reason_name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`reason_id`),
  UNIQUE KEY `reason_name` (`reason_name`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `adjustment_reasons`
--

LOCK TABLES `adjustment_reasons` WRITE;
/*!40000 ALTER TABLE `adjustment_reasons` DISABLE KEYS */;
INSERT INTO `adjustment_reasons` VALUES (1,'Hết hạn sử dụng (Write-off)',1),(2,'Hư hỏng vật lý / Bao bì móp méo',1),(3,'Thất thoát không rõ nguyên nhân',1),(4,'Sản phẩm lỗi từ Nhà cung cấp',1);
/*!40000 ALTER TABLE `adjustment_reasons` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `api_configs`
--

DROP TABLE IF EXISTS `api_configs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `api_configs` (
  `config_id` int(11) NOT NULL AUTO_INCREMENT,
  `api_name` varchar(50) NOT NULL,
  `endpoint_url` varchar(255) NOT NULL,
  `api_key` varchar(255) NOT NULL,
  `configured_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`config_id`),
  KEY `configured_by` (`configured_by`),
  CONSTRAINT `api_configs_ibfk_1` FOREIGN KEY (`configured_by`) REFERENCES `accounts` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `api_configs`
--

LOCK TABLES `api_configs` WRITE;
/*!40000 ALTER TABLE `api_configs` DISABLE KEYS */;
INSERT INTO `api_configs` VALUES (1,'AI_Demand_Forecast','http://127.0.0.1:8000/forecast','0167f04cc69a4ceff1ba1fe46aed5d352d0bc5dd56107c6f',1),(2,'Supplier_EDI_Gate','https://edi.gs25.vn/gateway','sk_edi_gs25_888',1),(3,'Zalo_ZNS_API','https://business.openapi.zalo.me/message/template','zalo_access_token_mock_123',1),(4,'Email_SMTP','smtp.gmail.com','smtp_app_password_mock_456',1);
/*!40000 ALTER TABLE `api_configs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL,
  `action_type` varchar(100) NOT NULL,
  `target_table` varchar(50) DEFAULT NULL,
  `target_id` int(11) DEFAULT NULL,
  `timestamp` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `account_id` (`account_id`),
  CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
INSERT INTO `audit_logs` VALUES (1,1,'UPDATE_REORDER_RULE','reorder_rules',1,'2026-07-21 15:36:24'),(2,1,'APPROVE_PO','purchase_orders',1,'2026-07-22 15:36:24'),(3,1,'APPROVE_PO','purchase_orders',2,'2026-07-24 15:36:24'),(4,1,'APPROVE_PO','purchase_orders',3,'2026-07-25 15:36:24'),(5,1,'APPROVE_PO','purchase_orders',4,'2026-07-26 15:36:24'),(6,1,'APPROVE_PO','purchase_orders',5,'2026-07-27 15:36:24'),(7,1,'APPROVE_PO','purchase_orders',6,'2026-07-28 15:36:24'),(8,1,'APPROVE_PO','purchase_orders',7,'2026-07-28 15:36:24'),(9,2,'OVERRIDE_PO_QTY','purchase_order_details',9,'2026-07-26 15:36:24'),(10,3,'OVERRIDE_PO_QTY','purchase_order_details',12,'2026-07-27 15:36:24'),(11,1,'REJECT_PO','purchase_orders',13,'2026-07-27 15:36:24'),(12,1,'APPROVE_PO','purchase_orders',14,'2026-07-29 00:00:00');
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `backup_history`
--

DROP TABLE IF EXISTS `backup_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `backup_history` (
  `backup_id` int(11) NOT NULL AUTO_INCREMENT,
  `backup_type` enum('full','restore') NOT NULL DEFAULT 'full',
  `file_path` varchar(255) DEFAULT NULL,
  `file_size_bytes` bigint(20) DEFAULT NULL,
  `status` enum('running','success','failed') NOT NULL DEFAULT 'running',
  `error_message` text DEFAULT NULL,
  `started_by` int(11) NOT NULL,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `finished_at` datetime DEFAULT NULL,
  PRIMARY KEY (`backup_id`),
  KEY `started_by` (`started_by`),
  CONSTRAINT `backup_history_ibfk_1` FOREIGN KEY (`started_by`) REFERENCES `accounts` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `backup_history`
--

LOCK TABLES `backup_history` WRITE;
/*!40000 ALTER TABLE `backup_history` DISABLE KEYS */;
INSERT INTO `backup_history` VALUES (1,'full','backend/storage/backups/backup_2024-01-05_020000.sql',4321987,'success',NULL,1,'2026-07-21 15:36:24','2026-07-21 15:37:06'),(2,'full','backend/storage/backups/backup_2024-01-04_020000.sql',4298112,'success',NULL,1,'2026-07-20 15:36:24','2026-07-20 15:37:03'),(3,'full',NULL,NULL,'failed','mysqldump: Got error: 2002 - No such file or directory (trying to connect via unix:/var/run/mysqld/mysqld.sock)',1,'2026-07-19 15:36:24','2026-07-19 15:36:27'),(4,'full',NULL,NULL,'failed','The system cannot find the path specified.',1,'2026-07-29 15:45:59','2026-07-29 15:45:59'),(5,'full',NULL,NULL,'running',NULL,1,'2026-07-29 15:52:50',NULL);
/*!40000 ALTER TABLE `backup_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `categories` (
  `category_id` int(11) NOT NULL AUTO_INCREMENT,
  `category_name` varchar(50) NOT NULL,
  `category_type` enum('FMCG','Fresh_Food','Imported_Korean') NOT NULL,
  `requires_fefo` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`category_id`),
  UNIQUE KEY `category_name` (`category_name`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categories`
--

LOCK TABLES `categories` WRITE;
/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
INSERT INTO `categories` VALUES (1,'Thực phẩm khô & Đóng gói','FMCG',0),(2,'Thức ăn chế biến sẵn (RTE)','Fresh_Food',1),(3,'Nhãn riêng & Nhập khẩu Hàn Quốc','Imported_Korean',0),(4,'Nước giải khát & Bia','FMCG',0),(5,'Sữa & Chế phẩm từ sữa','Fresh_Food',1),(6,'Bánh kẹo & Snack','FMCG',0),(7,'Hóa mỹ phẩm & Đồ dùng cá nhân','FMCG',0);
/*!40000 ALTER TABLE `categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_feedback`
--

DROP TABLE IF EXISTS `customer_feedback`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customer_feedback` (
  `feedback_id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) DEFAULT NULL,
  `logged_by` int(11) NOT NULL,
  `feedback_text` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`feedback_id`),
  KEY `product_id` (`product_id`),
  KEY `logged_by` (`logged_by`),
  CONSTRAINT `customer_feedback_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  CONSTRAINT `customer_feedback_ibfk_2` FOREIGN KEY (`logged_by`) REFERENCES `accounts` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_feedback`
--

LOCK TABLES `customer_feedback` WRITE;
/*!40000 ALTER TABLE `customer_feedback` DISABLE KEYS */;
INSERT INTO `customer_feedback` VALUES (1,1,4,'Khách hỏi mua Gimbap Bò lúc 7h tối nhưng đã hết hàng.','2026-07-24 15:36:24'),(2,12,5,'Khách phàn nàn sữa chuối Binggrae trên kệ bị móp méo.','2026-07-25 15:36:24'),(3,3,6,'Khách tìm Tteokbokki nhưng không thấy.','2026-07-26 15:36:24'),(4,27,7,'Khách chê mì Hảo Hảo dạo này hay đứt hàng.','2026-07-27 15:36:24'),(5,18,4,'Pepsi không calo trong tủ mát không đủ lạnh.','2026-07-28 15:36:24');
/*!40000 ALTER TABLE `customer_feedback` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `demand_forecasts`
--

DROP TABLE IF EXISTS `demand_forecasts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `demand_forecasts` (
  `forecast_id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `suggested_qty` int(11) NOT NULL,
  `api_status` enum('success','fallback_used') NOT NULL,
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`forecast_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `demand_forecasts_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `demand_forecasts`
--

LOCK TABLES `demand_forecasts` WRITE;
/*!40000 ALTER TABLE `demand_forecasts` DISABLE KEYS */;
INSERT INTO `demand_forecasts` VALUES (1,1,45,'success','2026-07-26 15:36:24'),(2,18,600,'success','2026-07-26 15:36:24'),(3,10,150,'fallback_used','2026-07-27 15:36:24'),(4,27,850,'success','2026-07-27 15:36:24'),(5,24,320,'success','2026-07-28 15:36:24'),(6,12,120,'fallback_used','2026-07-29 15:36:24');
/*!40000 ALTER TABLE `demand_forecasts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `email_status` enum('pending','sent','failed','not_required') NOT NULL DEFAULT 'not_required',
  `zalo_status` enum('pending','sent','failed','not_required') NOT NULL DEFAULT 'not_required',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`notification_id`),
  KEY `account_id` (`account_id`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`account_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
INSERT INTO `notifications` VALUES (1,2,'Cảnh báo Tồn kho thấp','Sản phẩm Gimbap Bò (RTE-GB-001) chạm Reorder Point.',1,'not_required','sent','2026-07-23 15:36:24'),(2,3,'Đơn hàng cần xử lý','NCC Samyang vừa xác nhận đơn hàng PO #3.',1,'sent','sent','2026-07-24 15:36:24'),(3,2,'Cảnh báo Tồn kho thấp','Mì Hảo Hảo (FMC-AC-001) chạm Reorder Point.',1,'not_required','sent','2026-07-26 15:36:24'),(4,1,'PO Đợi Duyệt','Manager Đỗ Thị Phương vừa trình PO #6.',1,'sent','not_required','2026-07-27 15:36:24'),(5,1,'PO Đợi Duyệt','Manager Lê Hà Bảo Trân vừa trình PO #7.',0,'pending','not_required','2026-07-27 15:36:24'),(6,2,'PO Bị Từ Chối','Admin đã từ chối PO #13.',0,'sent','sent','2026-07-27 15:36:24'),(7,2,'Cảnh báo Hết Hạn','Gimbap Bò (Batch 1) hết hạn vào ngày mai.',0,'not_required','sent','2026-07-29 15:36:24');
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permissions` (
  `permission_id` int(11) NOT NULL AUTO_INCREMENT,
  `permission_code` varchar(100) NOT NULL,
  `description` varchar(255) NOT NULL,
  PRIMARY KEY (`permission_id`),
  UNIQUE KEY `permission_code` (`permission_code`)
) ENGINE=InnoDB AUTO_INCREMENT=38 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permissions`
--

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
INSERT INTO `permissions` VALUES (1,'FR-ADM-01','Manage master data (Products, Suppliers, Warehouses)'),(2,'FR-ADM-04','Configure reorder rules'),(3,'FR-MGR-05','Submit Purchase Orders for approval'),(4,'FR-MGR-01','View Dashboard & Analytics'),(5,'FR-STF-02','Deduct stock on sales'),(6,'FR-STF-06','Record incoming stock (Goods Receipt)'),(7,'FR-ADM-02','Manage user accounts (create/edit/lock)'),(8,'FR-ADM-03','Manage permissions (assign roles/permissions)'),(9,'FR-ADM-05','Assign/manage category type for products'),(10,'FR-ADM-06','Approve or reject Purchase Orders submitted by Manager'),(11,'FR-ADM-07','View system-wide audit log'),(12,'FR-ADM-08','View KPI comparison reports over time'),(13,'FR-ADM-09','View store-wide inventory count history and discrepancies'),(14,'FR-ADM-10','Back up/restore data'),(15,'FR-MGR-02','View reorder suggestion list'),(16,'FR-MGR-03','Receive alerts when a product hits its reorder point'),(17,'FR-MGR-04','Edit suggested quantities before sending an order (override)'),(18,'FR-MGR-06','View purchase order status (pending/approved/delivered)'),(19,'FR-MGR-07','Handle/record stock-shortage incidents'),(20,'FR-MGR-08','View demand trend analysis'),(21,'FR-MGR-09','View product performance analysis reports'),(22,'FR-MGR-10','Receive suggested quantities from the demand-forecast API'),(23,'FR-MGR-11','View lead-time information for each supplier'),(24,'FR-MGR-12','View prioritized Top 10 Stock-out Risk list'),(25,'FR-STF-01','View current stock by product'),(26,'FR-STF-03','View list/alerts of low-stock products'),(27,'FR-STF-04','Record manual shelf counts (stock count)'),(28,'FR-STF-05','Confirm actual quantity received on delivery vs PO'),(29,'FR-STF-07','Cross-check received quantity vs PO, record discrepancies'),(30,'FR-STF-08','Record inventory adjustments (damaged/expired/lost) with reason'),(31,'FR-STF-09','Perform periodic stock counts, update system data'),(32,'FR-STF-10','View priority list of products needing urgent restock'),(33,'FR-STF-11','Record customer feedback/complaints related to stock-outs'),(34,'FR-STF-12','FEFO-based stock-out picking priority'),(35,'FR-STF-13','View sales history (7/30-day) for assigned products'),(36,'FR-STF-14','View inventory adjustment history by product'),(37,'FR-SYS-04','Trigger/request demand-forecast API call');
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `products` (
  `product_id` int(11) NOT NULL AUTO_INCREMENT,
  `sku_code` varchar(30) NOT NULL,
  `product_name` varchar(150) NOT NULL,
  `category_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `unit` varchar(20) NOT NULL,
  `shelf_life_days` int(11) DEFAULT NULL,
  `unit_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `selling_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`product_id`),
  UNIQUE KEY `sku_code` (`sku_code`),
  KEY `category_id` (`category_id`),
  KEY `supplier_id` (`supplier_id`),
  CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`),
  CONSTRAINT `products_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
INSERT INTO `products` VALUES (1,'RTE-GB-001','Gimbap Bò Bulgogi GS25',2,2,'Cuộn',2,18000.00,28000.00,1),(2,'RTE-GB-002','Gimbap Xúc Xích Phô Mai GS25',2,2,'Cuộn',2,16000.00,25000.00,1),(3,'RTE-TB-001','Tteokbokki Cay Ngọt Truyền Thống',2,2,'Hộp',3,22000.00,35000.00,1),(4,'RTE-ON-001','Cơm Nắm Cá Ngừ Mayonnaise',2,2,'Cái',2,12000.00,18000.00,1),(5,'RTE-SW-001','Sandwich Gà Teriyaki',2,2,'Gói',3,15000.00,25000.00,1),(6,'RTE-BM-001','Bánh Mì Que Thịt Bằm',2,2,'Cái',3,8000.00,13000.00,1),(7,'RTE-BB-001','Bánh Bao Xá Xíu Trứng Muối',2,2,'Cái',2,10000.00,15000.00,1),(8,'KOR-YU-001','Nước ép dưa hấu YouUs 270ml',3,1,'Chai',180,9000.00,14000.00,1),(9,'KOR-YU-002','Snack Tteokbokki YouUs',3,1,'Gói',180,11000.00,18000.00,1),(10,'KOR-SY-001','Mì gà cay Samyang Carbonara 130g',3,7,'Gói',360,13000.00,22000.00,1),(11,'KOR-SY-002','Mì gà cay Samyang Phô Mai 130g',3,7,'Gói',360,13000.00,22000.00,1),(12,'KOR-BG-001','Sữa chuối Binggrae 200ml',3,10,'Hộp',180,9500.00,15000.00,1),(13,'KOR-BG-002','Sữa dâu Binggrae 200ml',3,10,'Hộp',180,9500.00,15000.00,1),(14,'KOR-PD-001','Mì xào tương đen Jjajangmen Paldo',3,9,'Gói',360,14000.00,22000.00,1),(15,'KOR-LT-001','Nước ép nha đam Lotte 500ml',3,11,'Chai',360,16000.00,25000.00,1),(16,'DAI-VN-001','Sữa tươi Vinamilk không đường 180ml',5,12,'Hộp',180,6500.00,10000.00,1),(17,'DAI-TH-001','Sữa chua uống TH True Yogurt Dâu',5,13,'Chai',45,8500.00,12000.00,1),(18,'BEV-SP-001','Pepsi Không Calo 320ml',4,4,'Lon',360,6000.00,10000.00,1),(19,'BEV-SP-002','Trà Ô Long Tea+ Plus 455ml',4,4,'Chai',360,7500.00,12000.00,1),(20,'BEV-SP-003','Nước tăng lực Sting Dâu 330ml',4,4,'Chai',360,6500.00,11000.00,1),(21,'BEV-CC-001','Coca-Cola Plus 320ml',4,5,'Lon',360,6000.00,10000.00,1),(22,'BEV-CC-002','Nước khoáng Dasani 500ml',4,5,'Chai',360,4000.00,7000.00,1),(23,'BEV-NS-001','Cà phê rang xay Nescafe Lon',4,14,'Lon',360,9000.00,15000.00,1),(24,'BEV-HK-001','Bia Heineken Silver 330ml',4,20,'Lon',360,14000.00,22000.00,1),(25,'FMC-MS-001','Mì Omachi Xốt Spaghetti',1,3,'Gói',150,7000.00,10000.00,1),(26,'FMC-MS-002','Mì ly Kokomi Tôm Chua Cay',1,3,'Ly',150,8000.00,11000.00,1),(27,'FMC-AC-001','Mì Hảo Hảo Chua Cay',1,8,'Gói',180,3500.00,5000.00,1),(28,'FMC-OR-001','Snack khoai tây Ostar Tảo Biển',6,6,'Gói',180,6000.00,9000.00,1),(29,'FMC-OR-002','Bánh Chocopie hộp 12 cái',6,6,'Hộp',360,32000.00,45000.00,1),(30,'FMC-MD-001','Bánh quy Oreo Vani',6,15,'Thanh',360,5500.00,8000.00,1),(31,'PER-UL-001','Kem đánh răng P/S Trà Xanh 100g',7,16,'Tuýp',1080,15000.00,22000.00,1),(32,'PER-RH-001','Sữa rửa mặt Acnes 100g',7,17,'Tuýp',1080,38000.00,55000.00,1),(33,'PER-KA-001','Băng vệ sinh Laurier Dày',7,18,'Gói',1080,28000.00,40000.00,1);
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_order_details`
--

DROP TABLE IF EXISTS `purchase_order_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_order_details` (
  `po_detail_id` int(11) NOT NULL AUTO_INCREMENT,
  `po_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `suggested_qty` int(11) NOT NULL,
  `approved_qty` int(11) NOT NULL,
  `received_qty` int(11) DEFAULT NULL,
  `discrepancy_reason` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`po_detail_id`),
  KEY `po_id` (`po_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `purchase_order_details_ibfk_1` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`) ON DELETE CASCADE,
  CONSTRAINT `purchase_order_details_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_order_details`
--

LOCK TABLES `purchase_order_details` WRITE;
/*!40000 ALTER TABLE `purchase_order_details` DISABLE KEYS */;
INSERT INTO `purchase_order_details` VALUES (1,1,1,30,30,30,NULL),(2,1,2,20,20,18,'Giao thiếu 2'),(3,1,4,40,40,40,NULL),(4,2,18,500,400,400,NULL),(5,2,19,200,200,200,NULL),(6,3,10,100,100,100,NULL),(7,3,11,50,50,50,NULL),(8,4,8,100,100,100,NULL),(9,4,9,200,150,150,NULL),(10,5,25,200,200,200,NULL),(11,5,26,100,100,100,NULL),(12,6,21,300,300,NULL,NULL),(13,6,22,200,200,NULL,NULL),(14,7,27,800,800,NULL,NULL),(15,8,1,50,50,50,NULL),(16,8,3,20,20,20,NULL),(17,9,28,150,150,NULL,NULL),(18,9,29,50,50,NULL,NULL),(19,10,12,100,100,NULL,NULL),(20,10,13,100,100,NULL,NULL),(21,11,16,200,200,NULL,NULL),(22,12,23,100,100,NULL,NULL),(23,13,31,50,50,NULL,'Từ chối do kho còn tồn nhiều'),(24,14,24,300,300,NULL,NULL);
/*!40000 ALTER TABLE `purchase_order_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_orders`
--

DROP TABLE IF EXISTS `purchase_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_orders` (
  `po_id` int(11) NOT NULL AUTO_INCREMENT,
  `supplier_id` int(11) NOT NULL,
  `created_by` int(11) NOT NULL,
  `status` enum('Draft','Pending','Approved','Rejected','Delivered') NOT NULL DEFAULT 'Draft',
  `approved_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `approved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`po_id`),
  KEY `supplier_id` (`supplier_id`),
  KEY `created_by` (`created_by`),
  KEY `approved_by` (`approved_by`),
  CONSTRAINT `purchase_orders_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`),
  CONSTRAINT `purchase_orders_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `accounts` (`account_id`),
  CONSTRAINT `purchase_orders_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `accounts` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_orders`
--

LOCK TABLES `purchase_orders` WRITE;
/*!40000 ALTER TABLE `purchase_orders` DISABLE KEYS */;
INSERT INTO `purchase_orders` VALUES (1,2,2,'Delivered',1,'2026-07-22 00:00:00','2026-07-22 00:00:00'),(2,4,2,'Delivered',1,'2026-07-23 00:00:00','2026-07-24 00:00:00'),(3,7,3,'Delivered',1,'2026-07-24 00:00:00','2026-07-25 00:00:00'),(4,1,2,'Delivered',1,'2026-07-25 00:00:00','2026-07-26 00:00:00'),(5,3,2,'Delivered',1,'2026-07-26 00:00:00','2026-07-27 00:00:00'),(6,5,3,'Approved',1,'2026-07-27 00:00:00','2026-07-28 00:00:00'),(7,8,2,'Approved',1,'2026-07-27 00:00:00','2026-07-28 00:00:00'),(8,2,2,'Delivered',1,'2026-07-28 00:00:00','2026-07-28 00:00:00'),(9,6,3,'Pending',NULL,'2026-07-29 00:00:00',NULL),(10,10,2,'Pending',NULL,'2026-07-29 00:00:00',NULL),(11,12,2,'Draft',NULL,'2026-07-29 00:00:00',NULL),(12,14,3,'Draft',NULL,'2026-07-29 00:00:00',NULL),(13,16,2,'Rejected',1,'2026-07-26 00:00:00','2026-07-27 00:00:00'),(14,20,3,'Approved',1,'2026-07-28 00:00:00','2026-07-29 00:00:00'),(15,11,2,'Draft',NULL,'2026-07-29 00:00:00',NULL),(16,18,2,'Pending',NULL,'2026-07-29 00:00:00',NULL),(17,13,3,'Pending',NULL,'2026-07-29 00:00:00',NULL),(18,9,2,'Draft',NULL,'2026-07-29 00:00:00',NULL),(19,15,2,'Draft',NULL,'2026-07-29 00:00:00',NULL),(20,17,3,'Draft',NULL,'2026-07-29 00:00:00',NULL);
/*!40000 ALTER TABLE `purchase_orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reorder_rules`
--

DROP TABLE IF EXISTS `reorder_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reorder_rules` (
  `rule_id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `min_stock` int(11) NOT NULL,
  `max_stock` int(11) NOT NULL,
  `safety_stock` int(11) NOT NULL,
  `reorder_point` int(11) NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`rule_id`),
  KEY `category_id` (`category_id`),
  KEY `product_id` (`product_id`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `reorder_rules_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`),
  CONSTRAINT `reorder_rules_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  CONSTRAINT `reorder_rules_ibfk_3` FOREIGN KEY (`updated_by`) REFERENCES `accounts` (`account_id`),
  CONSTRAINT `chk_rule_target` CHECK (`category_id` is not null or `product_id` is not null)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reorder_rules`
--

LOCK TABLES `reorder_rules` WRITE;
/*!40000 ALTER TABLE `reorder_rules` DISABLE KEYS */;
INSERT INTO `reorder_rules` VALUES (1,1,NULL,50,300,30,80,1,'2026-07-29 15:36:23'),(2,2,NULL,5,30,3,10,1,'2026-07-29 15:36:23'),(3,3,NULL,20,200,15,40,1,'2026-07-29 15:36:23'),(4,4,NULL,60,500,40,100,1,'2026-07-29 15:36:23'),(5,6,NULL,30,250,20,50,1,'2026-07-29 15:36:23'),(6,NULL,1,15,50,5,20,1,'2026-07-29 15:36:23'),(7,NULL,4,20,60,5,25,1,'2026-07-29 15:36:23'),(8,NULL,10,50,300,30,80,1,'2026-07-29 15:36:23'),(9,NULL,12,30,100,10,40,1,'2026-07-29 15:36:23'),(10,NULL,18,100,1000,50,200,1,'2026-07-29 15:36:23'),(11,NULL,27,200,2000,100,350,1,'2026-07-29 15:36:23');
/*!40000 ALTER TABLE `reorder_rules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_permissions`
--

DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_permissions` (
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `permission_id` (`permission_id`),
  CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE CASCADE,
  CONSTRAINT `role_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`permission_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_permissions`
--

LOCK TABLES `role_permissions` WRITE;
/*!40000 ALTER TABLE `role_permissions` DISABLE KEYS */;
INSERT INTO `role_permissions` VALUES (1,1),(1,2),(1,7),(1,8),(1,9),(1,10),(1,11),(1,12),(1,13),(1,14),(2,3),(2,4),(2,15),(2,16),(2,17),(2,18),(2,19),(2,20),(2,21),(2,22),(2,23),(2,24),(2,37),(3,5),(3,6),(3,25),(3,26),(3,27),(3,28),(3,29),(3,30),(3,31),(3,32),(3,33),(3,34),(3,35),(3,36);
/*!40000 ALTER TABLE `role_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `role_id` int(11) NOT NULL AUTO_INCREMENT,
  `role_name` varchar(50) NOT NULL,
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `role_name` (`role_name`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'Admin'),(2,'Manager'),(3,'Store Staff');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sales_transaction_details`
--

DROP TABLE IF EXISTS `sales_transaction_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales_transaction_details` (
  `detail_id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity_sold` int(11) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `unit_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `batch_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`detail_id`),
  KEY `transaction_id` (`transaction_id`),
  KEY `product_id` (`product_id`),
  KEY `batch_id` (`batch_id`),
  CONSTRAINT `sales_transaction_details_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `sales_transactions` (`transaction_id`) ON DELETE CASCADE,
  CONSTRAINT `sales_transaction_details_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  CONSTRAINT `sales_transaction_details_ibfk_3` FOREIGN KEY (`batch_id`) REFERENCES `stock_batches` (`batch_id`),
  CONSTRAINT `chk_qty_sold` CHECK (`quantity_sold` > 0)
) ENGINE=InnoDB AUTO_INCREMENT=56 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sales_transaction_details`
--

LOCK TABLES `sales_transaction_details` WRITE;
/*!40000 ALTER TABLE `sales_transaction_details` DISABLE KEYS */;
INSERT INTO `sales_transaction_details` VALUES (1,1,1,1,28000.00,18000.00,1),(2,1,18,1,10000.00,6000.00,NULL),(3,2,4,2,18000.00,12000.00,4),(4,2,12,1,15000.00,9500.00,6),(5,3,10,1,22000.00,13000.00,NULL),(6,3,18,2,10000.00,6000.00,NULL),(7,4,27,3,5000.00,3500.00,NULL),(8,4,31,1,22000.00,15000.00,NULL),(9,5,2,1,25000.00,16000.00,NULL),(10,6,8,2,14000.00,9000.00,NULL),(11,7,19,1,12000.00,7500.00,NULL),(12,7,28,2,9000.00,6000.00,NULL),(13,8,24,6,22000.00,14000.00,NULL),(14,9,3,1,35000.00,22000.00,NULL),(15,10,29,1,45000.00,32000.00,NULL),(16,11,1,2,28000.00,18000.00,1),(17,12,4,1,18000.00,12000.00,4),(18,13,21,3,10000.00,6000.00,NULL),(19,14,27,5,5000.00,3500.00,NULL),(20,15,30,2,8000.00,5500.00,NULL),(21,16,22,1,7000.00,4000.00,NULL),(22,17,16,2,10000.00,6500.00,7),(23,18,17,3,12000.00,8500.00,NULL),(24,19,11,2,22000.00,13000.00,NULL),(25,20,14,1,22000.00,14000.00,NULL),(26,21,23,2,15000.00,9000.00,NULL),(27,22,25,4,10000.00,7000.00,NULL),(28,23,26,2,11000.00,8000.00,NULL),(29,24,7,2,15000.00,10000.00,NULL),(30,25,6,3,13000.00,8000.00,NULL),(31,26,5,1,25000.00,15000.00,NULL),(32,27,9,2,18000.00,11000.00,NULL),(33,28,33,1,40000.00,28000.00,NULL),(34,29,32,1,55000.00,38000.00,NULL),(35,30,18,3,10000.00,6000.00,NULL),(36,31,20,2,11000.00,6500.00,NULL),(37,32,1,1,28000.00,18000.00,1),(38,33,27,10,5000.00,3500.00,NULL),(39,34,10,2,22000.00,13000.00,NULL),(40,35,12,2,15000.00,9500.00,6),(41,36,15,1,25000.00,16000.00,NULL),(42,37,4,3,18000.00,12000.00,4),(43,38,13,2,15000.00,9500.00,NULL),(44,39,24,12,22000.00,14000.00,NULL),(45,40,21,2,10000.00,6000.00,NULL),(46,41,2,1,25000.00,16000.00,NULL),(47,42,8,1,14000.00,9000.00,NULL),(48,43,19,2,12000.00,7500.00,NULL),(49,44,28,3,9000.00,6000.00,NULL),(50,45,29,1,45000.00,32000.00,NULL),(51,46,31,2,22000.00,15000.00,NULL),(52,47,16,4,10000.00,6500.00,7),(53,48,27,5,5000.00,3500.00,NULL),(54,49,18,2,10000.00,6000.00,NULL),(55,50,1,2,28000.00,18000.00,2);
/*!40000 ALTER TABLE `sales_transaction_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sales_transactions`
--

DROP TABLE IF EXISTS `sales_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales_transactions` (
  `transaction_id` int(11) NOT NULL AUTO_INCREMENT,
  `performed_by` int(11) NOT NULL,
  `transaction_time` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`transaction_id`),
  KEY `performed_by` (`performed_by`),
  CONSTRAINT `sales_transactions_ibfk_1` FOREIGN KEY (`performed_by`) REFERENCES `accounts` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=51 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sales_transactions`
--

LOCK TABLES `sales_transactions` WRITE;
/*!40000 ALTER TABLE `sales_transactions` DISABLE KEYS */;
INSERT INTO `sales_transactions` VALUES (1,4,'2026-07-26 17:36:24'),(2,4,'2026-07-26 19:36:24'),(3,5,'2026-07-26 22:36:24'),(4,6,'2026-07-27 03:36:24'),(5,7,'2026-07-27 08:36:24'),(6,4,'2026-07-27 13:36:24'),(7,5,'2026-07-27 15:36:24'),(8,6,'2026-07-27 18:36:24'),(9,7,'2026-07-27 23:36:24'),(10,4,'2026-07-28 04:36:24'),(11,4,'2026-07-28 06:36:24'),(12,5,'2026-07-28 09:36:24'),(13,6,'2026-07-28 11:36:24'),(14,7,'2026-07-28 14:36:24'),(15,4,'2026-07-28 16:36:24'),(16,5,'2026-07-28 19:36:24'),(17,6,'2026-07-28 21:36:24'),(18,7,'2026-07-29 00:36:24'),(19,4,'2026-07-29 02:36:24'),(20,5,'2026-07-29 05:36:24'),(21,6,'2026-07-29 06:36:24'),(22,7,'2026-07-29 07:36:24'),(23,4,'2026-07-29 08:36:24'),(24,5,'2026-07-29 09:36:24'),(25,6,'2026-07-29 10:36:24'),(26,7,'2026-07-29 11:36:24'),(27,4,'2026-07-29 12:36:24'),(28,5,'2026-07-29 13:36:24'),(29,6,'2026-07-29 14:06:24'),(30,7,'2026-07-29 14:16:24'),(31,4,'2026-07-29 14:21:24'),(32,5,'2026-07-29 14:26:24'),(33,6,'2026-07-29 14:31:24'),(34,7,'2026-07-29 14:36:24'),(35,4,'2026-07-29 14:41:24'),(36,5,'2026-07-29 14:46:24'),(37,6,'2026-07-29 14:51:24'),(38,7,'2026-07-29 14:56:24'),(39,4,'2026-07-29 15:01:24'),(40,5,'2026-07-29 15:06:24'),(41,6,'2026-07-29 15:08:24'),(42,7,'2026-07-29 15:11:24'),(43,4,'2026-07-29 15:14:24'),(44,5,'2026-07-29 15:16:24'),(45,6,'2026-07-29 15:18:24'),(46,7,'2026-07-29 15:21:24'),(47,4,'2026-07-29 15:26:24'),(48,5,'2026-07-29 15:28:24'),(49,6,'2026-07-29 15:31:24'),(50,7,'2026-07-29 15:34:24');
/*!40000 ALTER TABLE `sales_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shortage_incidents`
--

DROP TABLE IF EXISTS `shortage_incidents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shortage_incidents` (
  `incident_id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `handled_by` int(11) NOT NULL,
  `resolution_action` varchar(255) DEFAULT NULL,
  `status` enum('Open','Resolved') NOT NULL DEFAULT 'Open',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`incident_id`),
  KEY `product_id` (`product_id`),
  KEY `handled_by` (`handled_by`),
  CONSTRAINT `shortage_incidents_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  CONSTRAINT `shortage_incidents_ibfk_2` FOREIGN KEY (`handled_by`) REFERENCES `accounts` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shortage_incidents`
--

LOCK TABLES `shortage_incidents` WRITE;
/*!40000 ALTER TABLE `shortage_incidents` DISABLE KEYS */;
INSERT INTO `shortage_incidents` VALUES (1,1,2,'Gọi giục CJ Foods giao hàng sớm.','Resolved','2026-07-24 15:36:24'),(2,10,3,'Tạo PO gấp nhưng NCC Samyang báo hết hàng kho tổng, chờ 3 ngày.','Open','2026-07-25 15:36:24'),(3,27,2,'Đã đặt thêm 800 gói từ Acecook.','Resolved','2026-07-27 15:36:24'),(4,24,3,'Lên đơn 300 lon Heineken chuẩn bị cuối tuần.','Resolved','2026-07-28 15:36:24'),(5,16,2,'Gửi PO cho Vinamilk.','Open','2026-07-29 15:36:24');
/*!40000 ALTER TABLE `shortage_incidents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock`
--

DROP TABLE IF EXISTS `stock`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock` (
  `stock_id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `warehouse_id` int(11) NOT NULL,
  `quantity_on_hand` int(11) NOT NULL DEFAULT 0,
  `last_updated` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`stock_id`),
  UNIQUE KEY `uq_product_warehouse` (`product_id`,`warehouse_id`),
  KEY `warehouse_id` (`warehouse_id`),
  CONSTRAINT `stock_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  CONSTRAINT `stock_ibfk_2` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`),
  CONSTRAINT `chk_qty_positive` CHECK (`quantity_on_hand` >= 0)
) ENGINE=InnoDB AUTO_INCREMENT=60 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock`
--

LOCK TABLES `stock` WRITE;
/*!40000 ALTER TABLE `stock` DISABLE KEYS */;
INSERT INTO `stock` VALUES (1,1,2,12,'2026-07-29 15:36:23'),(2,2,2,8,'2026-07-29 15:36:23'),(3,3,2,5,'2026-07-29 15:36:23'),(4,4,2,18,'2026-07-29 15:36:23'),(5,5,2,7,'2026-07-29 15:36:23'),(6,6,2,10,'2026-07-29 15:36:23'),(7,7,2,15,'2026-07-29 15:36:23'),(8,8,2,15,'2026-07-29 15:36:23'),(9,8,4,50,'2026-07-29 15:36:23'),(10,9,1,20,'2026-07-29 15:36:23'),(11,9,4,80,'2026-07-29 15:36:23'),(12,10,1,45,'2026-07-29 15:36:23'),(13,10,4,200,'2026-07-29 15:36:23'),(14,11,1,30,'2026-07-29 15:36:23'),(15,11,4,150,'2026-07-29 15:36:23'),(16,12,2,18,'2026-07-29 15:36:23'),(17,12,4,60,'2026-07-29 15:36:23'),(18,13,2,20,'2026-07-29 15:36:23'),(19,13,4,70,'2026-07-29 15:36:23'),(20,14,1,25,'2026-07-29 15:36:23'),(21,14,4,100,'2026-07-29 15:36:23'),(22,15,2,12,'2026-07-29 15:36:23'),(23,15,4,40,'2026-07-29 15:36:23'),(24,16,2,30,'2026-07-29 15:36:23'),(25,16,4,120,'2026-07-29 15:36:23'),(26,17,2,25,'2026-07-29 15:36:23'),(27,17,4,80,'2026-07-29 15:36:23'),(28,18,2,40,'2026-07-29 15:36:23'),(29,18,4,400,'2026-07-29 15:36:23'),(30,19,2,25,'2026-07-29 15:36:23'),(31,19,4,150,'2026-07-29 15:36:23'),(32,20,2,30,'2026-07-29 15:36:23'),(33,20,4,200,'2026-07-29 15:36:23'),(34,21,2,45,'2026-07-29 15:36:23'),(35,21,4,350,'2026-07-29 15:36:23'),(36,22,1,50,'2026-07-29 15:36:23'),(37,22,4,250,'2026-07-29 15:36:23'),(38,23,1,15,'2026-07-29 15:36:23'),(39,23,4,80,'2026-07-29 15:36:23'),(40,24,2,35,'2026-07-29 15:36:23'),(41,24,4,200,'2026-07-29 15:36:23'),(42,25,1,45,'2026-07-29 15:36:23'),(43,25,4,150,'2026-07-29 15:36:23'),(44,26,1,30,'2026-07-29 15:36:23'),(45,26,4,100,'2026-07-29 15:36:23'),(46,27,1,120,'2026-07-29 15:36:23'),(47,27,4,600,'2026-07-29 15:36:23'),(48,28,1,35,'2026-07-29 15:36:23'),(49,28,4,100,'2026-07-29 15:36:23'),(50,29,1,20,'2026-07-29 15:36:23'),(51,29,4,80,'2026-07-29 15:36:23'),(52,30,1,25,'2026-07-29 15:36:23'),(53,30,4,90,'2026-07-29 15:36:23'),(54,31,1,15,'2026-07-29 15:36:23'),(55,31,4,50,'2026-07-29 15:36:23'),(56,32,1,10,'2026-07-29 15:36:23'),(57,32,4,30,'2026-07-29 15:36:23'),(58,33,1,20,'2026-07-29 15:36:23'),(59,33,4,70,'2026-07-29 15:36:23');
/*!40000 ALTER TABLE `stock` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_batches`
--

DROP TABLE IF EXISTS `stock_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_batches` (
  `batch_id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `received_date` date NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `quantity_remaining` int(11) NOT NULL,
  PRIMARY KEY (`batch_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `stock_batches_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  CONSTRAINT `chk_batch_qty` CHECK (`quantity_remaining` >= 0)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_batches`
--

LOCK TABLES `stock_batches` WRITE;
/*!40000 ALTER TABLE `stock_batches` DISABLE KEYS */;
INSERT INTO `stock_batches` VALUES (1,1,'2026-07-28','2026-07-30',5),(2,1,'2026-07-29','2026-07-31',7),(3,2,'2026-07-28','2026-07-30',8),(4,4,'2026-07-28','2026-07-30',8),(5,4,'2026-07-29','2026-07-31',10),(6,12,'2026-06-29','2026-12-26',78),(7,16,'2026-07-19','2027-01-15',150);
/*!40000 ALTER TABLE `stock_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_count_details`
--

DROP TABLE IF EXISTS `stock_count_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_count_details` (
  `count_detail_id` int(11) NOT NULL AUTO_INCREMENT,
  `count_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `system_qty` int(11) NOT NULL,
  `actual_qty` int(11) NOT NULL,
  `discrepancy` int(11) GENERATED ALWAYS AS (`actual_qty` - `system_qty`) STORED,
  PRIMARY KEY (`count_detail_id`),
  KEY `count_id` (`count_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `stock_count_details_ibfk_1` FOREIGN KEY (`count_id`) REFERENCES `stock_counts` (`count_id`) ON DELETE CASCADE,
  CONSTRAINT `stock_count_details_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_count_details`
--

LOCK TABLES `stock_count_details` WRITE;
/*!40000 ALTER TABLE `stock_count_details` DISABLE KEYS */;
INSERT INTO `stock_count_details` VALUES (1,1,10,245,244,-1),(2,1,18,330,330,0),(3,1,27,600,595,-5),(4,2,1,15,15,0),(5,2,4,20,19,-1),(6,3,8,65,65,0),(7,3,12,80,80,0),(8,4,24,235,230,-5),(9,4,31,45,45,0),(10,5,1,9,8,-1),(11,5,12,78,78,0);
/*!40000 ALTER TABLE `stock_count_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_counts`
--

DROP TABLE IF EXISTS `stock_counts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_counts` (
  `count_id` int(11) NOT NULL AUTO_INCREMENT,
  `performed_by` int(11) NOT NULL,
  `count_date` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`count_id`),
  KEY `performed_by` (`performed_by`),
  CONSTRAINT `stock_counts_ibfk_1` FOREIGN KEY (`performed_by`) REFERENCES `accounts` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_counts`
--

LOCK TABLES `stock_counts` WRITE;
/*!40000 ALTER TABLE `stock_counts` DISABLE KEYS */;
INSERT INTO `stock_counts` VALUES (1,6,'2026-07-24 00:00:00'),(2,7,'2026-07-25 00:00:00'),(3,4,'2026-07-26 00:00:00'),(4,5,'2026-07-28 00:00:00'),(5,6,'2026-07-29 00:00:00');
/*!40000 ALTER TABLE `stock_counts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_movements`
--

DROP TABLE IF EXISTS `stock_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_movements` (
  `movement_id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `movement_type` enum('sale','stock_in','adjustment','count_correction') NOT NULL,
  `quantity_change` int(11) NOT NULL,
  `reason` varchar(100) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `performed_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`movement_id`),
  KEY `product_id` (`product_id`),
  KEY `performed_by` (`performed_by`),
  CONSTRAINT `stock_movements_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  CONSTRAINT `stock_movements_ibfk_2` FOREIGN KEY (`performed_by`) REFERENCES `accounts` (`account_id`),
  CONSTRAINT `chk_adjustment_reason` CHECK (`movement_type` <> 'adjustment' or `reason` is not null)
) ENGINE=InnoDB AUTO_INCREMENT=51 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_movements`
--

LOCK TABLES `stock_movements` WRITE;
/*!40000 ALTER TABLE `stock_movements` DISABLE KEYS */;
INSERT INTO `stock_movements` VALUES (1,1,'stock_in',30,NULL,1,2,'2026-07-22 15:36:24'),(2,2,'stock_in',18,NULL,1,2,'2026-07-22 15:36:24'),(3,18,'stock_in',400,NULL,2,2,'2026-07-24 15:36:24'),(4,19,'stock_in',200,NULL,2,2,'2026-07-24 15:36:24'),(5,10,'stock_in',100,NULL,3,3,'2026-07-25 15:36:24'),(6,11,'stock_in',50,NULL,3,3,'2026-07-25 15:36:24'),(7,8,'stock_in',100,NULL,4,2,'2026-07-26 15:36:24'),(8,9,'stock_in',150,NULL,4,2,'2026-07-26 15:36:24'),(9,25,'stock_in',200,NULL,5,2,'2026-07-27 15:36:24'),(10,26,'stock_in',100,NULL,5,2,'2026-07-27 15:36:24'),(11,1,'stock_in',50,NULL,8,2,'2026-07-28 15:36:24'),(12,3,'stock_in',20,NULL,8,2,'2026-07-28 15:36:24'),(13,1,'sale',-1,NULL,1,4,'2026-07-26 17:36:24'),(14,18,'sale',-1,NULL,1,4,'2026-07-26 17:36:24'),(15,4,'sale',-2,NULL,2,4,'2026-07-26 19:36:24'),(16,12,'sale',-1,NULL,2,4,'2026-07-26 19:36:24'),(17,10,'sale',-1,NULL,3,5,'2026-07-26 22:36:24'),(18,18,'sale',-2,NULL,3,5,'2026-07-26 22:36:24'),(19,27,'sale',-3,NULL,4,6,'2026-07-27 03:36:24'),(20,31,'sale',-1,NULL,4,6,'2026-07-27 03:36:24'),(21,2,'sale',-1,NULL,5,7,'2026-07-27 08:36:24'),(22,8,'sale',-2,NULL,6,4,'2026-07-27 13:36:24'),(23,19,'sale',-1,NULL,7,5,'2026-07-27 15:36:24'),(24,28,'sale',-2,NULL,7,5,'2026-07-27 15:36:24'),(25,24,'sale',-6,NULL,8,6,'2026-07-27 18:36:24'),(26,3,'sale',-1,NULL,9,7,'2026-07-27 23:36:24'),(27,29,'sale',-1,NULL,10,4,'2026-07-28 04:36:24'),(28,2,'adjustment',-2,'Hư hỏng vật lý / Bao bì móp méo',NULL,5,'2026-07-28 09:36:24'),(29,5,'adjustment',-1,'Hết hạn sử dụng (Write-off)',NULL,4,'2026-07-28 11:36:24'),(30,1,'sale',-2,NULL,11,4,'2026-07-28 06:36:24'),(31,4,'sale',-1,NULL,12,5,'2026-07-28 09:36:24'),(32,21,'sale',-3,NULL,13,6,'2026-07-28 11:36:24'),(33,27,'sale',-5,NULL,14,7,'2026-07-28 14:36:24'),(34,30,'sale',-2,NULL,15,4,'2026-07-28 16:36:24'),(35,22,'sale',-1,NULL,16,5,'2026-07-28 19:36:24'),(36,16,'sale',-2,NULL,17,6,'2026-07-28 21:36:24'),(37,17,'sale',-3,NULL,18,7,'2026-07-29 00:36:24'),(38,11,'sale',-2,NULL,19,4,'2026-07-29 02:36:24'),(39,14,'sale',-1,NULL,20,5,'2026-07-29 05:36:24'),(40,10,'count_correction',-1,NULL,1,6,'2026-07-28 00:00:00'),(41,27,'count_correction',-5,NULL,1,6,'2026-07-28 00:00:00'),(42,1,'count_correction',-1,NULL,2,7,'2026-07-29 00:00:00'),(43,23,'sale',-2,NULL,21,6,'2026-07-29 06:36:24'),(44,25,'sale',-4,NULL,22,7,'2026-07-29 07:36:24'),(45,26,'sale',-2,NULL,23,4,'2026-07-29 08:36:24'),(46,7,'sale',-2,NULL,24,5,'2026-07-29 09:36:24'),(47,6,'sale',-3,NULL,25,6,'2026-07-29 10:36:24'),(48,5,'sale',-1,NULL,26,7,'2026-07-29 11:36:24'),(49,9,'sale',-2,NULL,27,4,'2026-07-29 12:36:24'),(50,33,'sale',-1,NULL,28,5,'2026-07-29 13:36:24');
/*!40000 ALTER TABLE `stock_movements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `suppliers`
--

DROP TABLE IF EXISTS `suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `suppliers` (
  `supplier_id` int(11) NOT NULL AUTO_INCREMENT,
  `supplier_name` varchar(100) NOT NULL,
  `contact_phone` varchar(20) DEFAULT NULL,
  `avg_lead_time_days` decimal(5,1) DEFAULT NULL,
  PRIMARY KEY (`supplier_id`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suppliers`
--

LOCK TABLES `suppliers` WRITE;
/*!40000 ALTER TABLE `suppliers` DISABLE KEYS */;
INSERT INTO `suppliers` VALUES (1,'GS Retail (YouUs Direct)','028-1111-2222',7.0),(2,'CJ Foods Việt Nam','028-3333-4444',1.0),(3,'Masan Consumer','028-5555-6666',2.0),(4,'Suntory PepsiCo','028-7777-8888',1.5),(5,'Coca-Cola Việt Nam','028-9999-0000',1.5),(6,'Orion Vina','028-1234-5678',2.0),(7,'Samyang Foods','028-8765-4321',5.0),(8,'Acecook Việt Nam','028-2222-3333',2.0),(9,'Paldo Vina','028-4444-5555',3.0),(10,'Binggrae','028-6666-7777',3.0),(11,'Lotte Việt Nam','028-8888-9999',2.5),(12,'Vinamilk','028-0000-1111',1.0),(13,'TH True Milk','028-1111-3333',1.0),(14,'Nestle Việt Nam','028-2222-4444',2.0),(15,'Mondelez Kinh Đô','028-3333-5555',2.0),(16,'Unilever Việt Nam','028-4444-6666',3.0),(17,'Rohto Mentholatum','028-5555-7777',3.0),(18,'Kao Việt Nam','028-6666-8888',3.0),(19,'Bánh kẹo Phạm Nguyên','028-7777-9999',2.0),(20,'Heineken Việt Nam','028-8888-0000',2.0);
/*!40000 ALTER TABLE `suppliers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `system_settings`
--

DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_settings` (
  `setting_id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(50) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_id`),
  UNIQUE KEY `setting_key` (`setting_key`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `system_settings_ibfk_1` FOREIGN KEY (`updated_by`) REFERENCES `accounts` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_settings`
--

LOCK TABLES `system_settings` WRITE;
/*!40000 ALTER TABLE `system_settings` DISABLE KEYS */;
INSERT INTO `system_settings` VALUES (1,'max_inventory_discrepancy','5','Ngưỡng chênh lệch kiểm kê (%)',1,'2026-07-29 15:36:23'),(2,'zalo_alert_enabled','true','Bật gửi cảnh báo qua Zalo',1,'2026-07-29 15:36:23');
/*!40000 ALTER TABLE `system_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `warehouses`
--

DROP TABLE IF EXISTS `warehouses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `warehouses` (
  `warehouse_id` int(11) NOT NULL AUTO_INCREMENT,
  `warehouse_name` varchar(50) NOT NULL,
  `location` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`warehouse_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `warehouses`
--

LOCK TABLES `warehouses` WRITE;
/*!40000 ALTER TABLE `warehouses` DISABLE KEYS */;
INSERT INTO `warehouses` VALUES (1,'Kệ trưng bày (Sales Floor)','Khu vực khách hàng'),(2,'Tủ mát (Chiller)','Khu vực khách hàng'),(3,'Tủ đông (Freezer)','Khu vực khách hàng'),(4,'Kho sau (Backroom)','Phòng kho nội bộ');
/*!40000 ALTER TABLE `warehouses` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-07-29 15:52:53
