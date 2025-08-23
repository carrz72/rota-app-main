-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 31, 2025 at 09:05 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `rota_app`
--

-- --------------------------------------------------------

--
-- Table structure for table `branches`
--

CREATE TABLE `branches` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `code` varchar(10) NOT NULL,
  `address` text DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `manager_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status` enum('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `branches`
--

INSERT INTO `branches` (`id`, `name`, `code`, `address`, `phone`, `email`, `manager_user_id`, `created_at`, `updated_at`, `status`) VALUES
(1, 'Main Branch', 'MAIN', '123 Main Street, City Center', '+1-555-0101', 'main@company.com', NULL, '2025-07-29 17:04:10', '2025-07-29 17:04:10', 'active'),
(2, 'North Branch', 'NORTH', '456 North Avenue, North District', '+1-555-0102', 'north@company.com', NULL, '2025-07-29 17:04:10', '2025-07-29 17:04:10', 'active'),
(3, 'South Branch', 'SOUTH', '789 South Road, South District', '+1-555-0103', 'south@company.com', NULL, '2025-07-29 17:04:10', '2025-07-29 17:04:10', 'active'),
(4, 'Mansfield', 'EAST', '321 East Boulevard, East Side', '+1-555-0104', 'east@company.com', NULL, '2025-07-29 17:04:10', '2025-07-29 17:16:41', 'active'),
(5, 'Derby', 'WEST', '654 West Street, West End', '+1-555-0105', 'west@company.com', NULL, '2025-07-29 17:04:10', '2025-07-29 17:16:13', 'active'),
(6, 'The lion', 'NG1 3AH', '23 clumber street', '07503936693', 'thelion@gmail.com', NULL, '2025-07-29 17:06:46', '2025-07-29 17:06:46', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `branch_permissions`
--

CREATE TABLE `branch_permissions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `permission_level` enum('view','manage','admin') DEFAULT 'view',
  `granted_by_user_id` int(11) DEFAULT NULL,
  `granted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cross_branch_shift_requests`
--

CREATE TABLE `cross_branch_shift_requests` (
  `id` int(11) NOT NULL,
  `requesting_branch_id` int(11) NOT NULL,
  `target_branch_id` int(11) NOT NULL,
  `shift_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `role_required` varchar(100) DEFAULT NULL,
  `urgency_level` enum('low','medium','high','critical') DEFAULT 'medium',
  `description` text DEFAULT NULL,
  `requested_by_user_id` int(11) NOT NULL,
  `status` enum('pending','fulfilled','declined','expired') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL,
  `fulfilled_by_user_id` int(11) DEFAULT NULL,
  `fulfilled_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `role_id` int(11) DEFAULT NULL,
  `accepted_by_user_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cross_branch_shift_requests`
--

INSERT INTO `cross_branch_shift_requests` (`id`, `requesting_branch_id`, `target_branch_id`, `shift_date`, `start_time`, `end_time`, `role_required`, `urgency_level`, `description`, `requested_by_user_id`, `status`, `created_at`, `expires_at`, `fulfilled_by_user_id`, `fulfilled_at`, `notes`, `role_id`, `accepted_by_user_id`) VALUES
(8, 5, 6, '2025-08-04', '14:47:00', '12:46:00', NULL, 'medium', '', 2, '', '2025-07-31 11:46:33', '2025-08-01 00:46:33', 3, '2025-07-31 11:48:13', NULL, NULL, 3),
(9, 5, 6, '2025-08-03', '07:52:00', '14:53:00', NULL, 'medium', '', 2, '', '2025-07-31 11:52:20', '2025-08-01 12:52:20', 3, '2025-07-31 11:52:37', NULL, NULL, 3),
(33, 5, 6, '2025-08-04', '21:04:00', '01:02:00', NULL, 'medium', '', 2, 'pending', '2025-07-31 19:02:27', '2025-08-02 20:02:27', NULL, NULL, NULL, 9, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `decline_responses`
--

CREATE TABLE `decline_responses` (
  `id` int(11) NOT NULL,
  `invitation_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `responded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `decline_responses`
--

INSERT INTO `decline_responses` (`id`, `invitation_id`, `user_id`, `responded_at`) VALUES
(2, 12, 3, '2025-03-25 16:13:32'),
(3, 13, 3, '2025-03-25 16:15:33'),
(4, 15, 3, '2025-03-25 16:39:23'),
(7, 16, 3, '2025-03-26 13:56:31'),
(8, 14, 3, '2025-03-26 14:13:41'),
(9, 18, 3, '2025-03-29 22:48:15');

-- --------------------------------------------------------

--
-- Table structure for table `email_verification`
--

CREATE TABLE `email_verification` (
  `email` varchar(255) NOT NULL,
  `verification_code` varchar(10) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `email_verification`
--

INSERT INTO `email_verification` (`email`, `verification_code`, `created_at`) VALUES
('Attebilacarrington@gmail.com', '551070', '2025-03-26 12:42:02'),
('kelly123@gmail.com', '192087', '2025-03-26 15:41:18'),
('lolamam77@gmail.com', '749978', '2025-07-29 22:06:09'),
('lolamanm77@gmail.com', '598617', '2025-07-29 23:53:14'),
('lolamnm77@gmail.com', '921983', '2025-07-29 22:05:10'),
('lolmanm77@gmail.com', '383325', '2025-07-29 23:53:55'),
('test@example.com', '360483', '2025-07-29 22:43:03');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` varchar(20) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `related_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `type`, `message`, `is_read`, `created_at`, `related_id`) VALUES
(117, 3, 'shift-invite', 'You have a new shift invitation. Click to view details.', 1, '2025-05-09 23:04:45', 19),
(124, 14, 'schedule', 'New shift: 21:30 - 08:00 on May 7, 2025', 0, '2025-05-10 16:56:24', NULL),
(125, 14, 'schedule', 'New shift: 21:45 - 08:00 on May 8, 2025', 0, '2025-05-10 16:56:24', NULL),
(126, 14, 'schedule', 'New shift: 21:30 - 08:00 on May 9, 2025', 0, '2025-05-10 16:56:24', NULL),
(132, 14, 'info', 'Your shift on Fri, May 9, 2025 from 9:30 PM to 8:00 AM has been updated by management.', 0, '2025-05-10 16:58:47', NULL),
(134, 14, 'info', 'Your shift on Thu, May 8, 2025 from 9:45 PM to 8:00 AM has been updated by management.', 0, '2025-05-10 16:59:53', NULL),
(138, 14, 'schedule', 'Shift updated: 21:30 - 08:00 on May 7, 2025', 0, '2025-05-10 17:15:42', NULL),
(139, 14, 'schedule', 'Shift updated: 21:45 - 08:00 on May 8, 2025', 0, '2025-05-10 17:15:42', NULL),
(140, 14, 'schedule', 'Shift updated: 21:30 - 08:00 on May 9, 2025', 0, '2025-05-10 17:15:42', NULL),
(146, 14, 'schedule', 'New shift: 21:30 - 08:00 on May 10, 2025', 0, '2025-05-10 18:19:04', NULL),
(147, 14, 'schedule', 'New shift: 21:30 - 08:00 on May 11, 2025', 0, '2025-05-10 18:19:04', NULL),
(148, 14, 'schedule', 'New shift: 21:45 - 08:00 on May 14, 2025', 0, '2025-05-10 18:19:04', NULL),
(155, 14, 'schedule', 'New shift: 21:45 - 08:00 on May 21, 2025', 0, '2025-05-10 18:29:20', NULL),
(156, 14, 'schedule', 'New shift: 21:30 - 08:00 on May 22, 2025', 0, '2025-05-10 18:29:20', NULL),
(157, 14, 'schedule', 'New shift: 21:45 - 08:00 on May 23, 2025', 0, '2025-05-10 18:29:20', NULL),
(164, 14, 'schedule', 'New shift: 21:45 - 08:00 on May 28, 2025', 0, '2025-05-10 18:32:27', NULL),
(165, 14, 'schedule', 'New shift: 21:30 - 08:00 on May 29, 2025', 0, '2025-05-10 18:32:27', NULL),
(166, 14, 'schedule', 'New shift: 21:30 - 08:00 on May 30, 2025', 0, '2025-05-10 18:32:27', NULL),
(173, 14, 'schedule', 'New shift: 21:30 - 08:00 on May 1, 2025', 0, '2025-05-10 18:33:48', NULL),
(174, 14, 'schedule', 'New shift: 21:30 - 08:00 on May 2, 2025', 0, '2025-05-10 18:33:48', NULL),
(175, 14, 'schedule', 'New shift: 21:30 - 08:00 on May 3, 2025', 0, '2025-05-10 18:33:48', NULL),
(179, 15, 'schedule', 'New shift: 14:00 - 22:30 on May 4, 2025', 0, '2025-05-11 08:37:17', NULL),
(180, 15, 'schedule', 'New shift: 13:00 - 21:45 on May 5, 2025', 0, '2025-05-11 08:37:17', NULL),
(181, 15, 'schedule', 'New shift: 08:00 - 15:15 on May 6, 2025', 0, '2025-05-11 08:37:17', NULL),
(182, 15, 'schedule', 'New shift: 08:00 - 15:00 on May 9, 2025', 0, '2025-05-11 08:37:17', NULL),
(186, 15, 'schedule', 'New shift: 08:00 - 15:00 on May 10, 2025', 0, '2025-05-11 08:40:21', NULL),
(187, 15, 'schedule', 'New shift: 08:00 - 15:00 on May 11, 2025', 0, '2025-05-11 08:40:21', NULL),
(188, 15, 'schedule', 'New shift: 15:00 - 21:45 on May 12, 2025', 0, '2025-05-11 08:40:21', NULL),
(189, 15, 'schedule', 'New shift: 08:00 - 13:30 on May 13, 2025', 0, '2025-05-11 08:40:21', NULL),
(190, 15, 'schedule', 'New shift: 08:00 - 15:00 on May 16, 2025', 0, '2025-05-11 08:40:21', NULL),
(191, 14, 'schedule', 'Shift updated: 21:30 - 08:00 on May 10, 2025', 0, '2025-05-11 08:40:21', NULL),
(192, 14, 'schedule', 'Shift updated: 21:30 - 08:00 on May 11, 2025', 0, '2025-05-11 08:40:21', NULL),
(193, 14, 'schedule', 'Shift updated: 21:45 - 08:00 on May 14, 2025', 0, '2025-05-11 08:40:21', NULL),
(200, 15, 'schedule', 'New shift: 08:00 - 14:30 on May 22, 2025', 0, '2025-05-11 08:41:00', NULL),
(201, 15, 'schedule', 'New shift: 15:00 - 21:45 on May 23, 2025', 0, '2025-05-11 08:41:00', NULL),
(202, 14, 'schedule', 'Shift updated: 21:45 - 08:00 on May 21, 2025', 0, '2025-05-11 08:41:00', NULL),
(203, 14, 'schedule', 'Shift updated: 21:30 - 08:00 on May 22, 2025', 0, '2025-05-11 08:41:00', NULL),
(204, 14, 'schedule', 'Shift updated: 21:45 - 08:00 on May 23, 2025', 0, '2025-05-11 08:41:00', NULL),
(211, 15, 'schedule', 'New shift: 08:00 - 15:15 on May 24, 2025', 0, '2025-05-11 08:41:08', NULL),
(212, 15, 'schedule', 'New shift: 15:00 - 21:45 on May 25, 2025', 0, '2025-05-11 08:41:08', NULL),
(213, 15, 'schedule', 'New shift: 15:00 - 21:45 on May 26, 2025', 0, '2025-05-11 08:41:08', NULL),
(214, 15, 'schedule', 'New shift: 13:30 - 22:00 on May 29, 2025', 0, '2025-05-11 08:41:08', NULL),
(215, 15, 'schedule', 'New shift: 13:45 - 21:45 on May 30, 2025', 0, '2025-05-11 08:41:08', NULL),
(216, 14, 'schedule', 'Shift updated: 21:45 - 08:00 on May 28, 2025', 0, '2025-05-11 08:41:08', NULL),
(217, 14, 'schedule', 'Shift updated: 21:30 - 08:00 on May 29, 2025', 0, '2025-05-11 08:41:08', NULL),
(218, 14, 'schedule', 'Shift updated: 21:30 - 08:00 on May 30, 2025', 0, '2025-05-11 08:41:08', NULL),
(225, 15, 'schedule', 'Shift updated: 08:00 - 15:00 on May 10, 2025', 0, '2025-05-11 21:21:00', NULL),
(226, 15, 'schedule', 'Shift updated: 07:40 - 22:00 on May 11, 2025', 0, '2025-05-11 21:21:00', NULL),
(227, 15, 'schedule', 'Shift updated: 15:00 - 21:45 on May 12, 2025', 0, '2025-05-11 21:21:00', NULL),
(228, 15, 'schedule', 'Shift updated: 08:00 - 13:30 on May 13, 2025', 0, '2025-05-11 21:21:00', NULL),
(229, 15, 'schedule', 'Shift updated: 08:00 - 15:00 on May 16, 2025', 0, '2025-05-11 21:21:00', NULL),
(240, 15, 'schedule', 'Shift updated: 08:00 - 14:30 on May 22, 2025', 0, '2025-05-12 07:14:26', NULL),
(241, 15, 'schedule', 'Shift updated: 15:00 - 21:45 on May 23, 2025', 0, '2025-05-12 07:14:26', NULL),
(243, 15, 'success', 'New shift added: Apr 19, 2025 (3:00 PM - 10:00 PM)', 0, '2025-05-12 22:15:00', NULL),
(244, 15, 'success', 'New shift added: Apr 20, 2025 (2:45 PM - 9:45 PM)', 0, '2025-05-12 22:16:09', NULL),
(245, 15, 'success', 'New shift added: Apr 21, 2025 (3:00 PM - 10:00 PM)', 0, '2025-05-12 22:17:06', NULL),
(246, 15, 'success', 'New shift added: Apr 25, 2025 (3:00 PM - 9:45 PM)', 0, '2025-05-12 22:17:53', NULL),
(247, 15, 'success', 'New shift added: Apr 27, 2025 (2:00 PM - 9:45 PM)', 0, '2025-05-12 22:18:47', NULL),
(248, 15, 'success', 'New shift added: Apr 28, 2025 (3:00 PM - 9:45 PM)', 0, '2025-05-12 22:19:21', NULL),
(249, 15, 'success', 'New shift added: May 2, 2025 (8:00 AM - 3:00 PM)', 0, '2025-05-12 22:20:09', NULL),
(250, 15, 'success', 'New shift added: May 18, 2025 (3:00 PM - 9:45 PM)', 0, '2025-05-12 22:23:38', NULL),
(251, 15, 'success', 'New shift added: May 19, 2025 (3:00 PM - 3:45 PM)', 0, '2025-05-12 22:24:07', NULL),
(252, 15, 'success', 'New shift added: May 25, 2025 (3:00 PM - 9:45 PM)', 0, '2025-05-12 22:25:31', NULL),
(253, 15, 'success', 'New shift added: May 26, 2025 (3:25 PM - 9:45 PM)', 0, '2025-05-12 22:25:59', NULL),
(254, 15, 'success', 'New shift added: May 29, 2025 (1:30 PM - 10:00 PM)', 0, '2025-05-12 22:26:35', NULL),
(255, 15, 'success', 'New shift added: May 30, 2025 (1:45 PM - 9:45 PM)', 0, '2025-05-12 22:27:13', NULL),
(256, 15, 'success', 'Shift deleted successfully.', 0, '2025-05-12 22:29:16', NULL),
(258, 15, 'shift_update', 'carrz updated your shift for May 19, 2025 (3:00 PM - 9:45 PM)', 0, '2025-05-16 22:28:54', NULL),
(263, 15, 'schedule', 'Shift updated: 08:00 - 15:15 on May 24, 2025', 0, '2025-05-17 23:51:37', NULL),
(264, 15, 'schedule', 'Shift updated: 15:00 - 21:45 on May 25, 2025', 0, '2025-05-17 23:51:37', NULL),
(265, 15, 'schedule', 'Shift updated: 15:00 - 21:45 on May 26, 2025', 0, '2025-05-17 23:51:37', NULL),
(266, 15, 'schedule', 'Shift updated: 13:30 - 22:00 on May 29, 2025', 0, '2025-05-17 23:51:37', NULL),
(267, 15, 'schedule', 'Shift updated: 13:45 - 21:45 on May 30, 2025', 0, '2025-05-17 23:51:37', NULL),
(271, 15, 'schedule', 'New shift: 10:00 - 18:00 on Jun 7, 2025', 0, '2025-05-17 23:51:40', NULL),
(272, 15, 'schedule', 'New shift: 15:00 - 21:45 on Jun 8, 2025', 0, '2025-05-17 23:51:40', NULL),
(273, 15, 'schedule', 'New shift: 09:00 - 14:00 on Jun 9, 2025', 0, '2025-05-17 23:51:40', NULL),
(274, 14, 'schedule', 'New shift: 21:30 - 08:00 on Jun 9, 2025', 0, '2025-05-17 23:51:40', NULL),
(275, 14, 'schedule', 'New shift: 21:30 - 08:00 on Jun 10, 2025', 0, '2025-05-17 23:51:40', NULL),
(276, 14, 'schedule', 'New shift: 21:30 - 08:00 on Jun 11, 2025', 0, '2025-05-17 23:51:40', NULL),
(283, 15, 'schedule', 'Shift updated: 10:00 - 18:00 on Jun 7, 2025', 0, '2025-05-17 23:51:44', NULL),
(284, 15, 'schedule', 'Shift updated: 15:00 - 21:45 on Jun 8, 2025', 0, '2025-05-17 23:51:44', NULL),
(285, 15, 'schedule', 'Shift updated: 09:00 - 14:00 on Jun 9, 2025', 0, '2025-05-17 23:51:44', NULL),
(286, 14, 'schedule', 'Shift updated: 21:30 - 08:00 on Jun 9, 2025', 0, '2025-05-17 23:51:44', NULL),
(287, 14, 'schedule', 'Shift updated: 21:30 - 08:00 on Jun 10, 2025', 0, '2025-05-17 23:51:44', NULL),
(288, 14, 'schedule', 'Shift updated: 21:30 - 08:00 on Jun 11, 2025', 0, '2025-05-17 23:51:44', NULL),
(294, 15, 'schedule', 'New shift: 08:00 - 15:00 on May 1, 2025', 0, '2025-05-17 23:51:48', NULL),
(295, 15, 'schedule', 'Shift updated: 15:00 - 21:45 on May 2, 2025', 0, '2025-05-17 23:51:48', NULL),
(296, 15, 'schedule', 'Shift updated: 15:00 - 21:45 on May 6, 2025', 0, '2025-05-17 23:51:48', NULL),
(297, 14, 'schedule', 'Shift updated: 21:30 - 08:00 on May 1, 2025', 0, '2025-05-17 23:51:48', NULL),
(298, 14, 'schedule', 'Shift updated: 21:30 - 08:00 on May 2, 2025', 0, '2025-05-17 23:51:48', NULL),
(299, 14, 'schedule', 'Shift updated: 21:30 - 08:00 on May 3, 2025', 0, '2025-05-17 23:51:48', NULL),
(305, 14, 'schedule', 'New shift: 21:30 - 08:00 on May 12, 2025', 0, '2025-05-17 23:51:51', NULL),
(308, 15, 'success', 'New shift added: Jun 1, 2025 (8:00 AM - 3:00 PM)', 0, '2025-05-30 10:25:09', NULL),
(309, 15, 'success', 'New shift added: Jun 2, 2025 (3:00 PM - 9:45 PM)', 0, '2025-05-30 10:25:45', NULL),
(310, 15, 'success', 'New shift added: Jun 6, 2025 (3:00 PM - 9:45 PM)', 0, '2025-05-30 10:26:29', NULL),
(311, 15, 'success', 'New shift added: Jun 7, 2025 (8:00 AM - 6:00 PM)', 0, '2025-05-30 10:27:40', NULL),
(312, 15, 'success', 'New shift added: Jun 13, 2025 (4:00 PM - 10:00 PM)', 0, '2025-05-30 10:29:26', NULL),
(318, 3, 'shift-invite', 'You have a new shift invitation. Click to view details.', 0, '2025-07-30 21:30:06', 20),
(320, 2, 'success', 'Shift deleted successfully.', 0, '2025-07-31 14:41:57', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `payroll_calculations`
--

CREATE TABLE `payroll_calculations` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `payroll_period_id` int(11) NOT NULL,
  `employment_type` enum('hourly','salaried') NOT NULL,
  `total_hours` decimal(8,2) DEFAULT NULL,
  `hourly_rate` decimal(8,2) DEFAULT NULL,
  `monthly_salary` decimal(10,2) DEFAULT NULL,
  `gross_pay` decimal(10,2) NOT NULL,
  `night_shift_hours` decimal(8,2) DEFAULT 0.00,
  `night_shift_pay` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payroll_periods`
--

CREATE TABLE `payroll_periods` (
  `id` int(11) NOT NULL,
  `period_type` enum('hourly','salaried') NOT NULL,
  `period_name` varchar(50) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `payment_date` date NOT NULL,
  `status` enum('pending','processed','paid') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payroll_periods`
--

INSERT INTO `payroll_periods` (`id`, `period_type`, `period_name`, `start_date`, `end_date`, `payment_date`, `status`, `created_at`, `updated_at`) VALUES
(1, 'salaried', 'January 2025', '2025-01-01', '2025-01-31', '2025-01-28', 'pending', '2025-07-29 15:00:13', '2025-07-29 15:00:13'),
(2, 'salaried', 'February 2025', '2025-02-01', '2025-02-28', '2025-02-28', 'pending', '2025-07-29 15:00:13', '2025-07-29 15:00:13'),
(3, 'salaried', 'March 2025', '2025-03-01', '2025-03-31', '2025-03-28', 'pending', '2025-07-29 15:00:13', '2025-07-29 15:00:13'),
(4, 'salaried', 'April 2025', '2025-04-01', '2025-04-30', '2025-04-28', 'pending', '2025-07-29 15:00:13', '2025-07-29 15:00:13'),
(5, 'salaried', 'May 2025', '2025-05-01', '2025-05-31', '2025-05-28', 'pending', '2025-07-29 15:00:13', '2025-07-29 15:00:13'),
(6, 'salaried', 'June 2025', '2025-06-01', '2025-06-30', '2025-06-28', 'pending', '2025-07-29 15:00:13', '2025-07-29 15:00:13'),
(7, 'salaried', 'July 2025', '2025-07-01', '2025-07-31', '2025-07-28', 'pending', '2025-07-29 15:00:13', '2025-07-29 15:00:13'),
(8, 'salaried', 'August 2025', '2025-08-01', '2025-08-31', '2025-08-28', 'pending', '2025-07-29 15:00:13', '2025-07-29 15:00:13'),
(9, 'salaried', 'September 2025', '2025-09-01', '2025-09-30', '2025-09-28', 'pending', '2025-07-29 15:00:13', '2025-07-29 15:00:13'),
(10, 'salaried', 'October 2025', '2025-10-01', '2025-10-31', '2025-10-28', 'pending', '2025-07-29 15:00:13', '2025-07-29 15:00:13'),
(11, 'salaried', 'November 2025', '2025-11-01', '2025-11-30', '2025-11-28', 'pending', '2025-07-29 15:00:13', '2025-07-29 15:00:13'),
(12, 'salaried', 'December 2025', '2025-12-01', '2025-12-31', '2025-12-18', 'pending', '2025-07-29 15:00:13', '2025-07-29 15:00:13'),
(13, 'hourly', 'Dec 15 - Jan 15, 2025', '2024-12-16', '2025-01-15', '2025-01-28', 'pending', '2025-07-29 15:00:13', '2025-07-29 15:00:13'),
(14, 'hourly', 'Jan 16 - Feb 15, 2025', '2025-01-16', '2025-02-15', '2025-02-28', 'pending', '2025-07-29 15:00:13', '2025-07-29 15:00:13'),
(15, 'hourly', 'Feb 16 - Mar 15, 2025', '2025-02-16', '2025-03-15', '2025-03-28', 'pending', '2025-07-29 15:00:13', '2025-07-29 15:00:13'),
(16, 'hourly', 'Mar 16 - Apr 15, 2025', '2025-03-16', '2025-04-15', '2025-04-28', 'pending', '2025-07-29 15:00:13', '2025-07-29 15:00:13'),
(17, 'hourly', 'Apr 16 - May 15, 2025', '2025-04-16', '2025-05-15', '2025-05-28', 'pending', '2025-07-29 15:00:13', '2025-07-29 15:00:13'),
(18, 'hourly', 'May 16 - Jun 15, 2025', '2025-05-16', '2025-06-15', '2025-06-28', 'pending', '2025-07-29 15:00:13', '2025-07-29 15:00:13'),
(19, 'hourly', 'Jun 16 - Jul 15, 2025', '2025-06-16', '2025-07-15', '2025-07-28', 'pending', '2025-07-29 15:00:13', '2025-07-29 15:00:13'),
(20, 'hourly', 'Jul 16 - Aug 15, 2025', '2025-07-16', '2025-08-15', '2025-08-28', 'pending', '2025-07-29 15:00:13', '2025-07-29 15:00:13'),
(21, 'hourly', 'Aug 16 - Sep 15, 2025', '2025-08-16', '2025-09-15', '2025-09-28', 'pending', '2025-07-29 15:00:13', '2025-07-29 15:00:13'),
(22, 'hourly', 'Sep 16 - Oct 15, 2025', '2025-09-16', '2025-10-15', '2025-10-28', 'pending', '2025-07-29 15:00:13', '2025-07-29 15:00:13'),
(23, 'hourly', 'Oct 16 - Nov 15, 2025', '2025-10-16', '2025-11-15', '2025-11-28', 'pending', '2025-07-29 15:00:13', '2025-07-29 15:00:13'),
(24, 'hourly', 'Nov 16 - Dec 15, 2025', '2025-11-16', '2025-12-15', '2025-12-18', 'pending', '2025-07-29 15:00:13', '2025-07-29 15:00:13');

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `base_pay` decimal(10,2) NOT NULL,
  `employment_type` enum('hourly','salaried') DEFAULT 'hourly',
  `monthly_salary` decimal(10,2) DEFAULT NULL,
  `has_night_pay` tinyint(1) DEFAULT 0,
  `night_shift_pay` decimal(10,2) DEFAULT NULL,
  `night_start_time` time DEFAULT NULL,
  `night_end_time` time DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `user_id`, `name`, `base_pay`, `employment_type`, `monthly_salary`, `has_night_pay`, `night_shift_pay`, `night_start_time`, `night_end_time`, `created_at`) VALUES
(2, 3, 'Relief Supervisor', 12.71, 'hourly', NULL, 1, 14.21, '23:00:00', '06:00:00', '2025-03-20 09:56:50'),
(4, 3, 'CSA', 12.21, 'hourly', NULL, 1, 13.71, '23:00:00', '06:00:00', '2025-03-20 10:06:10'),
(9, 2, 'Kwik Tan Assistant', 12.21, 'hourly', NULL, 0, NULL, NULL, NULL, '2025-05-11 02:04:12');

-- --------------------------------------------------------

--
-- Table structure for table `shifts`
--

CREATE TABLE `shifts` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role_id` int(11) DEFAULT NULL,
  `shift_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `location` varchar(255) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `earnings` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `shifts`
--

INSERT INTO `shifts` (`id`, `user_id`, `role_id`, `shift_date`, `start_time`, `end_time`, `location`, `branch_id`, `earnings`, `created_at`) VALUES
(1, 3, 4, '2025-03-09', '10:00:00', '18:00:00', 'The lion', 1, 0.00, '2025-03-20 10:10:08'),
(2, 3, 4, '2025-03-07', '08:00:00', '13:00:00', 'The lion', 1, 0.00, '2025-03-20 10:56:20'),
(3, 3, 4, '2025-03-01', '21:45:00', '08:00:00', 'The lion', 1, 0.00, '2025-03-20 21:08:20'),
(4, 3, 2, '2025-03-28', '21:30:00', '08:00:00', 'The lion ', 1, 0.00, '2025-03-20 21:08:33'),
(5, 3, 2, '2025-03-27', '21:30:00', '08:00:00', 'The lion', 1, 0.00, '2025-03-20 21:08:34'),
(6, 3, 4, '2025-03-02', '21:45:00', '08:00:00', 'The lion', 1, 0.00, '2025-03-20 21:09:03'),
(7, 3, 4, '2025-03-10', '08:00:00', '20:00:00', 'The lion', 1, 0.00, '2025-03-20 21:37:46'),
(8, 3, 4, '2025-03-11', '08:00:00', '20:00:00', 'The lion', 1, 0.00, '2025-03-20 21:37:53'),
(9, 3, 4, '2025-03-14', '08:00:00', '13:00:00', 'The lion', 1, 0.00, '2025-03-20 21:37:57'),
(12, 3, 4, '2025-03-17', '08:00:00', '21:00:00', 'The lion', 1, 0.00, '2025-03-21 12:03:57'),
(14, 3, 4, '2025-03-18', '08:00:00', '21:00:00', 'The lion', 1, 0.00, '2025-03-21 12:06:29'),
(15, 3, 2, '2025-03-22', '21:45:00', '08:00:00', 'The lion', 1, 0.00, '2025-03-21 16:00:03'),
(16, 3, 4, '2025-03-26', '21:45:00', '08:00:00', 'The lion', 1, 0.00, '2025-03-22 12:35:05'),
(37, 3, 4, '2025-04-03', '21:45:00', '08:00:00', 'The lion ', 1, 0.00, '2025-03-26 21:55:32'),
(38, 3, 4, '2025-04-04', '21:45:00', '08:00:00', 'Mansfield ', 1, 0.00, '2025-03-26 21:56:35'),
(39, 3, 2, '2025-04-05', '21:30:00', '08:00:00', 'The lion ', 1, 0.00, '2025-03-26 21:57:27'),
(40, 3, 4, '2025-04-10', '21:45:00', '08:00:00', 'Mansfield ', 1, 0.00, '2025-03-26 21:59:12'),
(41, 3, 4, '2025-04-11', '21:45:00', '08:00:00', 'The lion ', 1, 0.00, '2025-03-26 22:00:09'),
(42, 3, 2, '2025-03-29', '21:45:00', '08:00:00', 'The lion ', 1, 0.00, '2025-03-28 23:13:45'),
(43, 3, 2, '2025-04-12', '21:45:00', '08:00:00', 'The lion ', 1, 0.00, '2025-04-03 21:12:52'),
(44, 3, 2, '2025-04-17', '21:45:00', '08:00:00', 'The lion ', 1, 0.00, '2025-04-03 21:13:37'),
(45, 3, 2, '2025-04-18', '21:45:00', '08:00:00', 'The lion ', 1, 0.00, '2025-04-03 21:14:39'),
(46, 3, 4, '2025-04-19', '21:45:00', '08:00:00', 'The lion ', 1, 0.00, '2025-04-03 21:15:09'),
(47, 3, 2, '2025-04-24', '21:45:00', '08:00:00', 'The lion ', 1, 0.00, '2025-04-03 21:16:39'),
(48, 3, 2, '2025-04-25', '21:45:00', '08:00:00', 'The lion ', 1, 0.00, '2025-04-03 21:17:45'),
(49, 3, 2, '2025-04-26', '21:45:00', '08:00:00', 'The lion ', 1, 0.00, '2025-04-03 21:18:39'),
(51, 3, 4, '2025-05-02', '21:45:00', '08:00:00', 'Derby ', 1, 0.00, '2025-04-03 21:21:13'),
(52, 13, 4, '2025-04-05', '21:45:00', '08:00:00', 'The lion', 1, 0.00, '2025-04-05 21:51:40'),
(53, 13, 4, '2025-04-10', '21:45:00', '08:00:00', 'The lion', 1, 0.00, '2025-04-05 21:52:10'),
(54, 13, 4, '2025-04-11', '21:30:00', '08:00:00', 'Mansfield ', 1, 0.00, '2025-04-05 21:52:46'),
(55, 13, 4, '2025-04-12', '21:45:00', '08:00:00', 'The lion', 1, 0.00, '2025-04-05 21:53:23'),
(56, 13, 4, '2025-04-16', '21:45:00', '08:00:00', 'The lion', 1, 0.00, '2025-04-05 21:54:05'),
(57, 13, 4, '2025-04-18', '21:45:00', '08:00:00', 'The lion', 1, 0.00, '2025-04-05 21:55:53'),
(58, 13, 4, '2025-04-19', '21:45:00', '08:00:00', 'The lion', 1, 0.00, '2025-04-05 21:56:31'),
(59, 13, 4, '2025-05-01', '21:45:00', '08:00:00', 'The lion', 1, 0.00, '2025-04-05 21:59:21'),
(60, 13, 2, '2025-05-02', '21:30:00', '08:00:00', 'The lion ', 1, 0.00, '2025-04-05 21:59:53'),
(61, 3, 2, '2025-04-13', '21:30:00', '08:00:00', 'Mansfield ', 1, 0.00, '2025-04-07 10:43:44'),
(63, 3, 4, '2025-04-15', '14:00:00', '21:00:00', 'The lion', 1, 0.00, '2025-04-10 21:10:44'),
(64, 3, 2, '2025-05-10', '21:45:00', '09:00:00', 'The Lion', 1, 0.00, '2025-05-09 20:54:28'),
(66, 3, 4, '2025-05-16', '21:30:00', '08:00:00', 'Mansfield', 1, 0.00, '2025-05-09 20:56:01'),
(67, 3, 4, '2025-05-17', '21:45:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-09 20:57:37'),
(68, 3, 4, '2025-05-22', '21:30:00', '08:00:00', 'Mansfield', 1, 0.00, '2025-05-09 20:59:12'),
(69, 3, 2, '2025-05-23', '21:30:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-09 21:00:43'),
(70, 3, 2, '2025-05-24', '21:30:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-09 21:01:38'),
(71, 3, 4, '2025-05-29', '21:45:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-09 21:04:50'),
(72, 3, 4, '2025-05-30', '21:45:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-09 21:05:20'),
(74, 3, 4, '2025-05-09', '21:45:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-10 16:34:46'),
(77, 3, 2, '2025-05-03', '22:00:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-10 16:56:24'),
(79, 14, 4, '2025-05-07', '21:30:00', '08:00:00', 'Mansfield', 1, 0.00, '2025-05-10 16:56:24'),
(80, 14, 4, '2025-05-08', '21:45:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-10 16:56:24'),
(81, 14, 2, '2025-05-09', '21:30:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-10 16:56:24'),
(82, 13, 4, '2025-05-08', '21:30:00', '08:00:00', 'Mansfield', 1, 0.00, '2025-05-10 16:56:24'),
(83, 13, 4, '2025-05-09', '21:45:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-10 16:56:24'),
(84, 14, 4, '2025-05-10', '21:30:00', '08:00:00', 'Mansfield', 1, 0.00, '2025-05-10 18:19:04'),
(85, 14, 2, '2025-05-11', '21:30:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-10 18:19:04'),
(86, 14, 4, '2025-05-14', '21:45:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-10 18:19:04'),
(87, 13, 4, '2025-05-10', '21:45:00', '09:00:00', 'The Lion', 1, 0.00, '2025-05-10 18:19:04'),
(88, 13, 4, '2025-05-15', '21:30:00', '08:00:00', 'Mansfield', 1, 0.00, '2025-05-10 18:19:04'),
(89, 13, 4, '2025-05-16', '21:45:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-10 18:19:04'),
(90, 14, 4, '2025-05-21', '21:45:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-10 18:29:20'),
(91, 14, 2, '2025-05-22', '21:30:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-10 18:29:20'),
(92, 14, 4, '2025-05-23', '21:45:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-10 18:29:20'),
(93, 13, 4, '2025-05-17', '21:45:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-10 18:29:20'),
(94, 13, 4, '2025-05-22', '21:30:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-10 18:29:20'),
(95, 13, 4, '2025-05-23', '21:30:00', '08:00:00', 'Mansfield', 1, 0.00, '2025-05-10 18:29:20'),
(96, 14, 4, '2025-05-28', '21:45:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-10 18:32:27'),
(97, 14, 2, '2025-05-29', '21:30:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-10 18:32:27'),
(98, 14, 2, '2025-05-30', '21:30:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-10 18:32:27'),
(99, 13, 4, '2025-05-24', '21:45:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-10 18:32:27'),
(100, 13, 4, '2025-05-29', '21:30:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-10 18:32:27'),
(101, 13, 4, '2025-05-30', '21:45:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-10 18:32:27'),
(102, 3, 2, '2025-05-31', '21:30:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-10 18:33:48'),
(105, 14, 2, '2025-05-01', '21:30:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-10 18:33:48'),
(106, 14, 2, '2025-05-02', '21:30:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-10 18:33:48'),
(107, 14, 2, '2025-05-03', '21:30:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-10 18:33:48'),
(108, 15, 2, '2025-05-04', '14:00:00', '22:30:00', 'The Lion', 1, 0.00, '2025-05-11 08:37:17'),
(109, 15, 2, '2025-05-05', '13:00:00', '21:45:00', 'The Lion', 1, 0.00, '2025-05-11 08:37:17'),
(110, 15, 4, '2025-05-06', '15:00:00', '21:45:00', 'The Lion', 1, 0.00, '2025-05-11 08:37:17'),
(111, 15, 4, '2025-05-09', '08:00:00', '15:00:00', 'The Lion', 1, 0.00, '2025-05-11 08:37:17'),
(112, 15, 4, '2025-05-10', '08:00:00', '15:00:00', 'Mansfield', 1, 0.00, '2025-05-11 08:40:21'),
(113, 15, 4, '2025-05-11', '07:40:00', '22:00:00', 'The Lion', 1, 0.00, '2025-05-11 08:40:21'),
(114, 15, 4, '2025-05-12', '15:00:00', '21:45:00', 'The Lion', 1, 0.00, '2025-05-11 08:40:21'),
(115, 15, 4, '2025-05-13', '08:00:00', '13:30:00', 'Mansfield', 1, 0.00, '2025-05-11 08:40:21'),
(116, 15, 4, '2025-05-16', '08:00:00', '15:00:00', 'The Lion', 1, 0.00, '2025-05-11 08:40:21'),
(117, 15, 2, '2025-05-22', '08:00:00', '14:30:00', 'The Lion', 1, 0.00, '2025-05-11 08:41:00'),
(118, 15, 4, '2025-05-23', '15:00:00', '21:45:00', 'The Lion', 1, 0.00, '2025-05-11 08:41:00'),
(119, 15, 4, '2025-05-24', '08:00:00', '15:15:00', 'The Lion', 1, 0.00, '2025-05-11 08:41:08'),
(120, 15, 4, '2025-05-25', '15:00:00', '21:45:00', 'The Lion', 1, 0.00, '2025-05-11 08:41:08'),
(121, 15, 4, '2025-05-26', '15:00:00', '21:45:00', 'The Lion', 1, 0.00, '2025-05-11 08:41:08'),
(122, 15, 4, '2025-05-29', '13:30:00', '22:00:00', 'Mansfield', 1, 0.00, '2025-05-11 08:41:08'),
(123, 15, 4, '2025-05-30', '13:45:00', '21:45:00', 'The Lion', 1, 0.00, '2025-05-11 08:41:08'),
(124, 15, 2, '2025-04-19', '15:00:00', '22:00:00', 'Nottingham', 1, 0.00, '2025-05-12 22:15:00'),
(125, 15, 2, '2025-04-20', '14:45:00', '21:45:00', 'Nottingham ', 1, 0.00, '2025-05-12 22:16:09'),
(126, 15, 4, '2025-04-21', '15:00:00', '22:00:00', 'Nottingham', 1, 0.00, '2025-05-12 22:17:06'),
(127, 15, 4, '2025-04-25', '15:00:00', '21:45:00', 'Nottingham', 1, 0.00, '2025-05-12 22:17:53'),
(128, 15, 2, '2025-04-27', '14:00:00', '21:45:00', 'Nottingham ', 1, 0.00, '2025-05-12 22:18:47'),
(129, 15, 4, '2025-04-28', '15:00:00', '21:45:00', 'Nottingham ', 1, 0.00, '2025-05-12 22:19:21'),
(130, 15, 4, '2025-05-02', '15:00:00', '21:45:00', 'The Lion', 1, 0.00, '2025-05-12 22:20:09'),
(131, 15, 4, '2025-05-18', '15:00:00', '21:45:00', 'Nottingham', 1, 0.00, '2025-05-12 22:23:38'),
(132, 15, 4, '2025-05-19', '15:00:00', '21:45:00', 'Nottingham ', 1, 0.00, '2025-05-12 22:24:07'),
(137, 3, 4, '2025-06-07', '21:45:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-17 23:51:40'),
(138, 3, 4, '2025-06-12', '21:45:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-17 23:51:40'),
(139, 3, 4, '2025-06-13', '21:45:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-17 23:51:40'),
(141, 15, 4, '2025-06-08', '15:00:00', '21:45:00', 'The Lion', 1, 0.00, '2025-05-17 23:51:40'),
(142, 15, 4, '2025-06-09', '09:00:00', '14:00:00', 'The Lion', 1, 0.00, '2025-05-17 23:51:40'),
(143, 14, 2, '2025-06-09', '21:30:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-17 23:51:40'),
(144, 14, 2, '2025-06-10', '21:30:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-17 23:51:40'),
(145, 14, 2, '2025-06-11', '21:30:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-17 23:51:40'),
(146, 13, 4, '2025-06-07', '21:45:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-17 23:51:40'),
(147, 13, 4, '2025-06-08', '21:45:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-17 23:51:40'),
(148, 13, 4, '2025-06-13', '21:45:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-17 23:51:40'),
(151, 15, 4, '2025-05-01', '08:00:00', '15:00:00', 'The Lion', 1, 0.00, '2025-05-17 23:51:48'),
(152, 13, 4, '2025-05-31', '21:45:00', '08:00:00', 'The Lion', 1, 0.00, '2025-05-17 23:51:48'),
(155, 14, 2, '2025-05-12', '21:30:00', '08:00:00', 'Mansfield', 1, 0.00, '2025-05-17 23:51:51'),
(156, 15, 4, '2025-06-01', '08:00:00', '15:00:00', 'Nottingham', 1, 0.00, '2025-05-30 10:25:09'),
(157, 15, 4, '2025-06-02', '15:00:00', '21:45:00', 'Nottingham', 1, 0.00, '2025-05-30 10:25:45'),
(158, 15, 4, '2025-06-06', '15:00:00', '21:45:00', 'Nottingham', 1, 0.00, '2025-05-30 10:26:29'),
(159, 15, 9, '2025-06-07', '08:00:00', '18:00:00', 'Nottingham', 1, 0.00, '2025-05-30 10:27:40'),
(160, 15, 4, '2025-06-13', '16:00:00', '22:00:00', 'Beeston', 1, 0.00, '2025-05-30 10:29:26'),
(170, 2, NULL, '2025-08-08', '11:14:00', '17:17:00', 'Cross-branch coverage', 6, 0.00, '2025-07-31 11:19:41'),
(171, 2, NULL, '2025-08-04', '12:24:00', '00:24:00', 'Cross-branch coverage', 6, 0.00, '2025-07-31 11:25:21'),
(172, 2, NULL, '2025-08-04', '12:27:00', '23:27:00', 'Cross-branch coverage', 6, 0.00, '2025-07-31 11:27:37'),
(173, 2, NULL, '2025-08-04', '15:32:00', '11:31:00', 'Cross-branch coverage', 6, 0.00, '2025-07-31 11:30:07'),
(174, 2, NULL, '2025-08-04', '15:32:00', '11:31:00', 'Cross-branch coverage', 6, 0.00, '2025-07-31 11:30:07'),
(175, 2, NULL, '2025-08-04', '12:34:00', '20:36:00', 'Cross-branch coverage', 6, 0.00, '2025-07-31 11:35:22'),
(176, 2, NULL, '2025-08-04', '12:34:00', '20:36:00', 'Cross-branch coverage', 5, 0.00, '2025-07-31 11:35:22'),
(177, 2, NULL, '2025-08-04', '12:39:00', '13:40:00', 'Cross-branch coverage', 6, 0.00, '2025-07-31 11:40:21'),
(178, 2, NULL, '2025-08-04', '12:39:00', '13:40:00', 'Cross-branch coverage', 5, 0.00, '2025-07-31 11:40:21'),
(183, 2, NULL, '2025-07-31', '17:29:00', '20:30:00', 'Cross-branch coverage', 6, 0.00, '2025-07-31 12:25:26'),
(184, 2, NULL, '2025-07-31', '17:29:00', '20:30:00', 'Coverage at The lion', 6, 0.00, '2025-07-31 12:25:26'),
(185, 2, NULL, '2025-08-04', '15:30:00', '18:33:00', 'Cross-branch coverage', 6, 0.00, '2025-07-31 12:29:18'),
(186, 2, NULL, '2025-08-04', '15:30:00', '18:33:00', 'Coverage at The lion', 6, 0.00, '2025-07-31 12:29:18'),
(187, 2, NULL, '2025-08-01', '13:38:00', '17:37:00', 'Cross-branch coverage', 6, 0.00, '2025-07-31 12:34:17'),
(188, 2, NULL, '2025-08-01', '13:38:00', '17:37:00', 'Coverage at The lion', 6, 0.00, '2025-07-31 12:34:17'),
(189, 2, NULL, '2025-08-04', '16:23:00', '21:21:00', 'Cross-branch coverage', 6, 0.00, '2025-07-31 14:02:33'),
(194, 2, NULL, '2025-08-01', '15:32:00', '17:34:00', 'Coverage at The lion', 6, 0.00, '2025-07-31 14:38:20'),
(195, 2, NULL, '2025-08-04', '19:00:00', '20:45:00', 'Coverage at The lion', 6, 0.00, '2025-07-31 14:41:31'),
(196, 2, NULL, '2025-08-07', '15:42:00', '08:50:00', 'Coverage at The lion', 6, 0.00, '2025-07-31 14:43:50'),
(198, 2, NULL, '2025-08-02', '15:51:00', '20:00:00', 'Coverage at The lion', 6, 0.00, '2025-07-31 14:51:46'),
(200, 3, NULL, '2025-08-01', '16:09:00', '20:13:00', 'Coverage at Derby', 5, 0.00, '2025-07-31 15:20:53'),
(201, 2, NULL, '2025-08-01', '17:23:00', '22:22:00', 'Coverage at The lion', 6, 0.00, '2025-07-31 15:23:06'),
(202, 3, NULL, '2025-08-02', '16:30:00', '21:25:00', 'Coverage at Derby', 5, 0.00, '2025-07-31 15:25:36'),
(203, 2, NULL, '2025-08-05', '18:55:00', '23:56:00', 'Coverage at The lion', 6, 0.00, '2025-07-31 17:56:34'),
(204, 3, NULL, '2025-08-02', '18:59:00', '00:59:00', 'Coverage at Derby', 5, 0.00, '2025-07-31 18:00:03'),
(205, 3, 9, '2025-08-10', '20:40:00', '01:45:00', 'Coverage at Derby', 5, 0.00, '2025-07-31 18:42:16'),
(206, 3, 2, '2025-08-05', '19:52:00', '01:52:00', 'Coverage at Derby', 5, 0.00, '2025-07-31 18:52:37');

-- --------------------------------------------------------

--
-- Table structure for table `shift_coverage`
--

CREATE TABLE `shift_coverage` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `covered_by_user_id` int(11) NOT NULL,
  `covered_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shift_invitations`
--

CREATE TABLE `shift_invitations` (
  `id` int(11) NOT NULL,
  `shift_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `role_id` int(11) NOT NULL,
  `location` varchar(255) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `shift_invitations`
--

INSERT INTO `shift_invitations` (`id`, `shift_date`, `start_time`, `end_time`, `role_id`, `location`, `admin_id`, `user_id`, `status`, `created_at`) VALUES
(11, '2025-03-27', '23:03:00', '08:02:00', 2, 'The lion', 2, NULL, 'accepted', '2025-03-24 22:02:23'),
(12, '2025-03-24', '22:04:00', '10:04:00', 2, 'The lion', 2, NULL, 'pending', '2025-03-24 22:04:50'),
(13, '2025-03-25', '22:41:00', '08:41:00', 2, 'The lion', 2, NULL, 'pending', '2025-03-24 22:41:38'),
(14, '2025-03-26', '16:15:00', '18:17:00', 2, 'The lion', 2, NULL, 'pending', '2025-03-25 16:15:17'),
(15, '2025-03-25', '16:36:00', '17:37:00', 2, 'The lion', 2, NULL, 'pending', '2025-03-25 16:36:22'),
(16, '2025-03-28', '13:55:00', '23:55:00', 2, 'The lion', 2, NULL, 'pending', '2025-03-26 13:56:03'),
(17, '2025-03-29', '21:45:00', '08:00:00', 2, 'Mansfield', 2, NULL, 'accepted', '2025-03-28 23:13:21'),
(18, '2025-03-30', '09:00:00', '01:01:00', 3, 'The lion', 2, NULL, 'pending', '2025-03-29 08:50:09'),
(19, '2025-05-24', '21:45:00', '08:00:00', 4, 'Mansfield', 2, 3, 'declined', '2025-05-09 23:04:45'),
(20, '2025-07-31', '21:00:00', '08:00:00', 4, 'Derby', 2, 3, 'pending', '2025-07-30 21:30:06');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','user') DEFAULT 'user',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `role_id` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `email_verified` tinyint(1) DEFAULT 0,
  `reset_code` varchar(6) DEFAULT NULL,
  `attempts` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password`, `role`, `created_at`, `role_id`, `branch_id`, `email_verified`, `reset_code`, `attempts`) VALUES
(2, 'carrz1', 'carrz723@gmail.com', '$2y$10$V/2h9OK8SaUgSH9wPnRmWOUi.2pWWZjvXLDSCI0SHyvRL/RsvuxJW', 'admin', '2025-03-19 13:28:55', NULL, 5, 1, '568324', 0),
(3, 'Carrington Attebila', 'carringtonattebila@gmail.com', '$2y$10$L5fYHfNFgFicyfzwocU1mum5sXq5teh7kX/DIF6VGQDFxQIBtjQRO', 'user', '2025-03-19 13:47:03', NULL, 6, 1, '149801', 0),
(13, 'Jaedon Henry-Allen', 'jhenryallen05@gmail.com', '$2y$10$XkOsftEl7WkFuGsOuoVV9ekYkPdPRZhpyXRpSjeU4eKKD2rwhI9x2', 'user', '2025-04-05 21:49:33', NULL, 6, 1, NULL, 0),
(14, 'Dimitri Hanson', 'dimitrihanson11@icloud.com', '$2y$10$SxYPxLdY/9pUP3f6fMrfLuytd2SBtfTiGux6bl5vFHyZtD70WeM4e', 'user', '2025-05-10 02:36:16', NULL, 6, 1, NULL, 0),
(15, 'Nakita Cooper', 'nakitacooper@hotmail.com', '$2y$10$ttnstkHyb0cRwNUNy8JwpOTC5ct1wU6ZeJ4UUZ5u9o979bxqchasW', 'user', '2025-05-11 08:13:02', NULL, 6, 1, '453892', 0),
(47, 'test use 33', 'lolmanm77@gmail.com', '$2y$10$M009k7gk504c63IecCvomOZVuv7ZkKT8zpFQlxgcGoJwqM95w4m36', 'user', '2025-07-29 23:54:11', NULL, 6, 1, NULL, 0);

-- --------------------------------------------------------

--
-- Table structure for table `user_activity_log`
--

CREATE TABLE `user_activity_log` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_activity_log`
--

INSERT INTO `user_activity_log` (`id`, `user_id`, `action`, `details`, `created_at`) VALUES
(1, 2, 'branch_change', '{\"old_branch_id\":6,\"new_branch_id\":5,\"old_branch_name\":\"The lion\",\"new_branch_name\":\"Derby\"}', '2025-07-31 09:14:29');

-- --------------------------------------------------------

--
-- Table structure for table `user_sessions`
--

CREATE TABLE `user_sessions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `session_token` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `branches`
--
ALTER TABLE `branches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_branch_code` (`code`),
  ADD KEY `idx_branch_status` (`status`);

--
-- Indexes for table `branch_permissions`
--
ALTER TABLE `branch_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_branch` (`user_id`,`branch_id`),
  ADD KEY `granted_by_user_id` (`granted_by_user_id`),
  ADD KEY `idx_permissions_user` (`user_id`),
  ADD KEY `idx_permissions_branch` (`branch_id`);

--
-- Indexes for table `cross_branch_shift_requests`
--
ALTER TABLE `cross_branch_shift_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `target_branch_id` (`target_branch_id`),
  ADD KEY `requested_by_user_id` (`requested_by_user_id`),
  ADD KEY `fulfilled_by_user_id` (`fulfilled_by_user_id`),
  ADD KEY `idx_request_status` (`status`),
  ADD KEY `idx_request_date` (`shift_date`),
  ADD KEY `idx_request_branches` (`requesting_branch_id`,`target_branch_id`),
  ADD KEY `idx_expires_at` (`expires_at`),
  ADD KEY `fk_accepted_by_user` (`accepted_by_user_id`);

--
-- Indexes for table `decline_responses`
--
ALTER TABLE `decline_responses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `invitation_id` (`invitation_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `email_verification`
--
ALTER TABLE `email_verification`
  ADD PRIMARY KEY (`email`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `payroll_calculations`
--
ALTER TABLE `payroll_calculations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `payroll_period_id` (`payroll_period_id`),
  ADD KEY `idx_payroll_calculations_user_period` (`user_id`,`payroll_period_id`);

--
-- Indexes for table `payroll_periods`
--
ALTER TABLE `payroll_periods`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_payroll_periods_type_status` (`period_type`,`status`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `shifts`
--
ALTER TABLE `shifts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `role_id` (`role_id`),
  ADD KEY `idx_shifts_date_user` (`shift_date`,`user_id`),
  ADD KEY `idx_shifts_branch` (`branch_id`);

--
-- Indexes for table `shift_coverage`
--
ALTER TABLE `shift_coverage`
  ADD PRIMARY KEY (`id`),
  ADD KEY `request_id` (`request_id`),
  ADD KEY `covered_by_user_id` (`covered_by_user_id`);

--
-- Indexes for table `shift_invitations`
--
ALTER TABLE `shift_invitations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `admin_id` (`admin_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `role_id` (`role_id`),
  ADD KEY `idx_users_branch` (`branch_id`);

--
-- Indexes for table `user_activity_log`
--
ALTER TABLE `user_activity_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `session_token` (`session_token`),
  ADD KEY `user_id` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `branches`
--
ALTER TABLE `branches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `branch_permissions`
--
ALTER TABLE `branch_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cross_branch_shift_requests`
--
ALTER TABLE `cross_branch_shift_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `decline_responses`
--
ALTER TABLE `decline_responses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=322;

--
-- AUTO_INCREMENT for table `payroll_calculations`
--
ALTER TABLE `payroll_calculations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payroll_periods`
--
ALTER TABLE `payroll_periods`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `shifts`
--
ALTER TABLE `shifts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=208;

--
-- AUTO_INCREMENT for table `shift_coverage`
--
ALTER TABLE `shift_coverage`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `shift_invitations`
--
ALTER TABLE `shift_invitations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=48;

--
-- AUTO_INCREMENT for table `user_activity_log`
--
ALTER TABLE `user_activity_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `user_sessions`
--
ALTER TABLE `user_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `branch_permissions`
--
ALTER TABLE `branch_permissions`
  ADD CONSTRAINT `branch_permissions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `branch_permissions_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `branch_permissions_ibfk_3` FOREIGN KEY (`granted_by_user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `cross_branch_shift_requests`
--
ALTER TABLE `cross_branch_shift_requests`
  ADD CONSTRAINT `cross_branch_shift_requests_ibfk_1` FOREIGN KEY (`requesting_branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `cross_branch_shift_requests_ibfk_2` FOREIGN KEY (`target_branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `cross_branch_shift_requests_ibfk_3` FOREIGN KEY (`requested_by_user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `cross_branch_shift_requests_ibfk_4` FOREIGN KEY (`fulfilled_by_user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_accepted_by_user` FOREIGN KEY (`accepted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `decline_responses`
--
ALTER TABLE `decline_responses`
  ADD CONSTRAINT `decline_responses_ibfk_1` FOREIGN KEY (`invitation_id`) REFERENCES `shift_invitations` (`id`),
  ADD CONSTRAINT `decline_responses_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `payroll_calculations`
--
ALTER TABLE `payroll_calculations`
  ADD CONSTRAINT `payroll_calculations_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payroll_calculations_ibfk_2` FOREIGN KEY (`payroll_period_id`) REFERENCES `payroll_periods` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `roles`
--
ALTER TABLE `roles`
  ADD CONSTRAINT `roles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `shifts`
--
ALTER TABLE `shifts`
  ADD CONSTRAINT `fk_shifts_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `shifts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `shifts_ibfk_2` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `shift_coverage`
--
ALTER TABLE `shift_coverage`
  ADD CONSTRAINT `shift_coverage_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `cross_branch_shift_requests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `shift_coverage_ibfk_2` FOREIGN KEY (`covered_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `shift_invitations`
--
ALTER TABLE `shift_invitations`
  ADD CONSTRAINT `shift_invitations_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `shift_invitations_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`);

--
-- Constraints for table `user_activity_log`
--
ALTER TABLE `user_activity_log`
  ADD CONSTRAINT `user_activity_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD CONSTRAINT `user_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
