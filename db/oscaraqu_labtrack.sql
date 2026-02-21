-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Feb 20, 2026 at 05:22 PM
-- Server version: 5.7.44-48
-- PHP Version: 8.3.26

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `oscaraqu_labtrack`
--

-- --------------------------------------------------------

--
-- Table structure for table `Batch`
--

CREATE TABLE `Batch` (
  `batch_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `chemical_id` int(11) NOT NULL,
  `lot_number` varchar(50) NOT NULL,
  `received_date` date NOT NULL,
  `expiration_date` date NOT NULL,
  `concentration` decimal(10,2) DEFAULT NULL,
  `unit_cost` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `Batch`
--

INSERT INTO `Batch` (`batch_id`, `supplier_id`, `chemical_id`, `lot_number`, `received_date`, `expiration_date`, `concentration`, `unit_cost`) VALUES
(1, 1, 1, 'ETOH-2401', '2026-01-10', '2028-01-10', NULL, 45.50),
(2, 2, 2, 'ACET-9920', '2026-01-12', '2028-01-12', NULL, 39.99),
(3, 3, 3, 'NACL-1102', '2026-01-15', '2030-01-15', NULL, 12.75),
(4, 1, 4, 'HCL-5501', '2026-01-18', '2027-07-18', 1.00, 28.25),
(5, 2, 5, 'NAOH-8822', '2026-01-20', '2029-01-20', NULL, 18.40),
(6, 3, 6, 'GLUC-3333', '2026-01-25', '2029-01-25', NULL, 15.00);

-- --------------------------------------------------------

--
-- Table structure for table `Chemical`
--

CREATE TABLE `Chemical` (
  `chemical_id` int(11) NOT NULL,
  `chemical_name` varchar(100) NOT NULL,
  `cas_number` varchar(50) NOT NULL,
  `hazard_class` varchar(50) NOT NULL,
  `default_unit` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `Chemical`
--

INSERT INTO `Chemical` (`chemical_id`, `chemical_name`, `cas_number`, `hazard_class`, `default_unit`) VALUES
(1, 'Ethanol', '64-17-5', 'Flammable', 'mL'),
(2, 'Acetone', '67-64-1', 'Flammable', 'mL'),
(3, 'Sodium Chloride', '7647-14-5', 'Irritant', 'g'),
(4, 'Hydrochloric Acid', '7647-01-0', 'Corrosive', 'mL'),
(5, 'Sodium Hydroxide', '1310-73-2', 'Corrosive', 'g'),
(6, 'Glucose', '50-99-7', 'Low Hazard', 'g');

-- --------------------------------------------------------

--
-- Table structure for table `Document`
--

CREATE TABLE `Document` (
  `document_id` int(11) NOT NULL,
  `lab_id` int(11) NOT NULL,
  `uploaded_by_user_id` int(11) NOT NULL,
  `doc_type` varchar(50) NOT NULL,
  `file_name` varchar(150) NOT NULL,
  `file_url` varchar(225) NOT NULL,
  `uploaded_at` datetime NOT NULL,
  `notes` text,
  `chemical_id` int(11) DEFAULT NULL,
  `experiment_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `Document`
--

INSERT INTO `Document` (`document_id`, `lab_id`, `uploaded_by_user_id`, `doc_type`, `file_name`, `file_url`, `uploaded_at`, `notes`, `chemical_id`, `experiment_id`) VALUES
(1, 1, 1, 'Protocol', 'buffer_protocol.pdf', 'https://example.com/buffer_protocol.pdf', '2026-02-10 11:00:00', 'Protocol used for prep', NULL, 1),
(2, 1, 2, 'SDS', 'ethanol_sds.pdf', 'https://example.com/ethanol_sds.pdf', '2026-02-11 16:00:00', 'Safety data sheet', 1, 2),
(3, 2, 4, 'Instruction', 'demo_outline.docx', 'https://example.com/demo_outline.docx', '2026-02-12 10:00:00', 'Teaching demo outline', NULL, 3),
(4, 1, 1, 'Protocol', 'buffer_protocol.pdf', 'https://example.com/buffer_protocol.pdf', '2026-02-10 11:00:00', 'Protocol used for prep', NULL, 1),
(5, 1, 2, 'SDS', 'ethanol_sds.pdf', 'https://example.com/ethanol_sds.pdf', '2026-02-11 16:00:00', 'Safety data sheet', 1, 2),
(6, 2, 4, 'Instruction', 'demo_outline.docx', 'https://example.com/demo_outline.docx', '2026-02-12 10:00:00', 'Teaching demo outline', NULL, 3);

-- --------------------------------------------------------

--
-- Table structure for table `Experiment`
--

CREATE TABLE `Experiment` (
  `experiment_id` int(11) NOT NULL,
  `lab_id` int(11) NOT NULL,
  `created_by_user_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` text,
  `created_at` datetime NOT NULL,
  `status` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `Experiment`
--

INSERT INTO `Experiment` (`experiment_id`, `lab_id`, `created_by_user_id`, `title`, `description`, `created_at`, `status`) VALUES
(1, 1, 1, 'Protein Wash Buffer Prep', 'Prepare buffer for imaging workflow', '2026-02-10 10:00:00', 'Active'),
(2, 1, 2, 'Solvent Cleaning Protocol', 'Cleaning glassware and equipment', '2026-02-11 14:30:00', 'Active'),
(3, 2, 4, 'Intro Chem Demo', 'Demonstration for teaching lab', '2026-02-12 09:00:00', 'Planned');

-- --------------------------------------------------------

--
-- Table structure for table `Inventory_item`
--

CREATE TABLE `Inventory_item` (
  `item_id` int(11) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `location_id` int(11) NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit` varchar(20) NOT NULL,
  `status` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `Inventory_item`
--

INSERT INTO `Inventory_item` (`item_id`, `batch_id`, `location_id`, `quantity`, `unit`, `status`) VALUES
(1, 1, 1, 4500.00, 'mL', 'In Stock'),
(2, 2, 1, 2000.00, 'mL', 'In Stock'),
(3, 3, 2, 5000.00, 'g', 'In Stock'),
(4, 4, 2, 1500.00, 'mL', 'In Stock'),
(5, 5, 2, 3000.00, 'g', 'In Stock'),
(6, 6, 3, 2500.00, 'g', 'In Stock');

-- --------------------------------------------------------

--
-- Table structure for table `Lab`
--

CREATE TABLE `Lab` (
  `lab_id` int(11) NOT NULL,
  `lab_name` varchar(100) NOT NULL,
  `department_id` varchar(50) NOT NULL,
  `building_name` varchar(100) NOT NULL,
  `room_num` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `Lab`
--

INSERT INTO `Lab` (`lab_id`, `lab_name`, `department_id`, `building_name`, `room_num`) VALUES
(1, 'RI-INBRE Core Lab', 'BIO', 'CBLS', '120'),
(2, 'Chemistry Teaching Lab', 'CHE', 'Beaupre Center', '215');

-- --------------------------------------------------------

--
-- Table structure for table `Storage_location`
--

CREATE TABLE `Storage_location` (
  `location_id` int(11) NOT NULL,
  `lab_id` int(11) NOT NULL,
  `location_name` varchar(100) NOT NULL,
  `location_type` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `Storage_location`
--

INSERT INTO `Storage_location` (`location_id`, `lab_id`, `location_name`, `location_type`) VALUES
(1, 1, 'Flammables Cabinet A', 'Cabinet'),
(2, 1, 'Corrosives Shelf 1', 'Shelf'),
(3, 1, 'Cold Room Fridge', 'Fridge'),
(4, 2, 'Teaching Lab Cabinet 3', 'Cabinet');

-- --------------------------------------------------------

--
-- Table structure for table `Supplier`
--

CREATE TABLE `Supplier` (
  `supplier_id` int(11) NOT NULL,
  `supplier_name` varchar(100) NOT NULL,
  `contact_email` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `Supplier`
--

INSERT INTO `Supplier` (`supplier_id`, `supplier_name`, `contact_email`, `phone`) VALUES
(1, 'Fisher Scientific', 'support@fisher.com', '800-766-7000'),
(2, 'Sigma-Aldrich', 'support@sial.com', '800-325-3010'),
(3, 'VWR', 'support@vwr.com', '800-932-5000');

-- --------------------------------------------------------

--
-- Table structure for table `Usage_log`
--

CREATE TABLE `Usage_log` (
  `usage_id` int(11) NOT NULL,
  `experiment_id` int(11) NOT NULL,
  `inventory_item_id` int(11) NOT NULL,
  `used_by_user_id` int(11) NOT NULL,
  `used_at` datetime NOT NULL,
  `amount_used` decimal(10,2) NOT NULL,
  `unit` varchar(20) NOT NULL,
  `notes` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `Usage_log`
--

INSERT INTO `Usage_log` (`usage_id`, `experiment_id`, `inventory_item_id`, `used_by_user_id`, `used_at`, `amount_used`, `unit`, `notes`) VALUES
(1, 1, 3, 1, '2026-02-10 10:20:00', 250.00, 'g', 'Used for buffer base'),
(2, 1, 4, 2, '2026-02-10 10:35:00', 100.00, 'mL', 'Dilution step'),
(3, 2, 1, 3, '2026-02-11 15:00:00', 500.00, 'mL', 'Glassware rinse'),
(4, 2, 2, 3, '2026-02-11 15:10:00', 200.00, 'mL', 'Surface cleaning'),
(5, 3, 1, 4, '2026-02-12 09:30:00', 150.00, 'mL', 'Demo solvent use');

-- --------------------------------------------------------

--
-- Table structure for table `User`
--

CREATE TABLE `User` (
  `user_id` int(11) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `role` varchar(50) NOT NULL,
  `lab_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `User`
--

INSERT INTO `User` (`user_id`, `first_name`, `last_name`, `email`, `role`, `lab_id`) VALUES
(1, 'Oscar', 'Aquino', 'oscar.aquino@uri.edu', 'Admin', 1),
(2, 'Maya', 'Singh', 'maya.singh@uri.edu', 'Lab Manager', 1),
(3, 'Ethan', 'Reyes', 'ethan.reyes@uri.edu', 'Research Assistant', 1),
(4, 'Lina', 'Chen', 'lina.chen@uri.edu', 'Student Worker', 2);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `Batch`
--
ALTER TABLE `Batch`
  ADD PRIMARY KEY (`batch_id`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `chemical_id` (`chemical_id`);

--
-- Indexes for table `Chemical`
--
ALTER TABLE `Chemical`
  ADD PRIMARY KEY (`chemical_id`),
  ADD UNIQUE KEY `cas_number` (`cas_number`);

--
-- Indexes for table `Document`
--
ALTER TABLE `Document`
  ADD PRIMARY KEY (`document_id`),
  ADD KEY `lab_id` (`lab_id`),
  ADD KEY `uploaded_by_user_id` (`uploaded_by_user_id`),
  ADD KEY `fk_document_chemical` (`chemical_id`),
  ADD KEY `fk_document_experiment` (`experiment_id`);

--
-- Indexes for table `Experiment`
--
ALTER TABLE `Experiment`
  ADD PRIMARY KEY (`experiment_id`),
  ADD KEY `lab_id` (`lab_id`),
  ADD KEY `created_by_user_id` (`created_by_user_id`);

--
-- Indexes for table `Inventory_item`
--
ALTER TABLE `Inventory_item`
  ADD PRIMARY KEY (`item_id`),
  ADD KEY `batch_id` (`batch_id`),
  ADD KEY `location_id` (`location_id`);

--
-- Indexes for table `Lab`
--
ALTER TABLE `Lab`
  ADD PRIMARY KEY (`lab_id`);

--
-- Indexes for table `Storage_location`
--
ALTER TABLE `Storage_location`
  ADD PRIMARY KEY (`location_id`),
  ADD KEY `lab_id` (`lab_id`);

--
-- Indexes for table `Supplier`
--
ALTER TABLE `Supplier`
  ADD PRIMARY KEY (`supplier_id`),
  ADD UNIQUE KEY `contact_email` (`contact_email`);

--
-- Indexes for table `Usage_log`
--
ALTER TABLE `Usage_log`
  ADD PRIMARY KEY (`usage_id`),
  ADD KEY `experiment_id` (`experiment_id`),
  ADD KEY `inventory_item_id` (`inventory_item_id`),
  ADD KEY `inventory_by_user_id` (`used_by_user_id`);

--
-- Indexes for table `User`
--
ALTER TABLE `User`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `lab_id` (`lab_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `Batch`
--
ALTER TABLE `Batch`
  MODIFY `batch_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `Chemical`
--
ALTER TABLE `Chemical`
  MODIFY `chemical_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `Document`
--
ALTER TABLE `Document`
  MODIFY `document_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `Experiment`
--
ALTER TABLE `Experiment`
  MODIFY `experiment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `Inventory_item`
--
ALTER TABLE `Inventory_item`
  MODIFY `item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `Lab`
--
ALTER TABLE `Lab`
  MODIFY `lab_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `Storage_location`
--
ALTER TABLE `Storage_location`
  MODIFY `location_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `Supplier`
--
ALTER TABLE `Supplier`
  MODIFY `supplier_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `Usage_log`
--
ALTER TABLE `Usage_log`
  MODIFY `usage_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `User`
--
ALTER TABLE `User`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `Batch`
--
ALTER TABLE `Batch`
  ADD CONSTRAINT `Batch_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `Supplier` (`supplier_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `Batch_ibfk_2` FOREIGN KEY (`chemical_id`) REFERENCES `Chemical` (`chemical_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_batch_chemical` FOREIGN KEY (`chemical_id`) REFERENCES `Chemical` (`chemical_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_batch_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `Supplier` (`supplier_id`) ON UPDATE CASCADE;

--
-- Constraints for table `Document`
--
ALTER TABLE `Document`
  ADD CONSTRAINT `Document_ibfk_1` FOREIGN KEY (`lab_id`) REFERENCES `Lab` (`lab_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `Document_ibfk_2` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `User` (`user_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `Document_ibfk_3` FOREIGN KEY (`chemical_id`) REFERENCES `Chemical` (`chemical_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `Document_ibfk_4` FOREIGN KEY (`experiment_id`) REFERENCES `Experiment` (`experiment_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_document_chemical` FOREIGN KEY (`chemical_id`) REFERENCES `Chemical` (`chemical_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_document_experiment` FOREIGN KEY (`experiment_id`) REFERENCES `Experiment` (`experiment_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_document_lab` FOREIGN KEY (`lab_id`) REFERENCES `Lab` (`lab_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_document_user` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `User` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `Experiment`
--
ALTER TABLE `Experiment`
  ADD CONSTRAINT `Experiment_ibfk_1` FOREIGN KEY (`lab_id`) REFERENCES `Lab` (`lab_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `Experiment_ibfk_2` FOREIGN KEY (`created_by_user_id`) REFERENCES `User` (`user_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_experiment_lab` FOREIGN KEY (`lab_id`) REFERENCES `Lab` (`lab_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_experiment_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `User` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `Inventory_item`
--
ALTER TABLE `Inventory_item`
  ADD CONSTRAINT `Inventory_item_ibfk_1` FOREIGN KEY (`batch_id`) REFERENCES `Batch` (`batch_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `Inventory_item_ibfk_2` FOREIGN KEY (`location_id`) REFERENCES `Storage_location` (`location_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_inventory_batch` FOREIGN KEY (`batch_id`) REFERENCES `Batch` (`batch_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_inventory_location` FOREIGN KEY (`location_id`) REFERENCES `Storage_location` (`location_id`) ON UPDATE CASCADE;

--
-- Constraints for table `Storage_location`
--
ALTER TABLE `Storage_location`
  ADD CONSTRAINT `Storage_location_ibfk_1` FOREIGN KEY (`lab_id`) REFERENCES `Lab` (`lab_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `Usage_log`
--
ALTER TABLE `Usage_log`
  ADD CONSTRAINT `Usage_log_ibfk_1` FOREIGN KEY (`experiment_id`) REFERENCES `Experiment` (`experiment_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `Usage_log_ibfk_2` FOREIGN KEY (`inventory_item_id`) REFERENCES `Inventory_item` (`item_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `Usage_log_ibfk_3` FOREIGN KEY (`used_by_user_id`) REFERENCES `User` (`user_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_usage_experiment` FOREIGN KEY (`experiment_id`) REFERENCES `Experiment` (`experiment_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_usage_item` FOREIGN KEY (`inventory_item_id`) REFERENCES `Inventory_item` (`item_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_usage_user` FOREIGN KEY (`used_by_user_id`) REFERENCES `User` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `User`
--
ALTER TABLE `User`
  ADD CONSTRAINT `User_ibfk_1` FOREIGN KEY (`lab_id`) REFERENCES `Lab` (`lab_id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
