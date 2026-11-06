SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

--
-- Table structure for table `api_keys`
--
CREATE TABLE `api_keys` (
  `id` int(11) NOT NULL,
  `key_value` varchar(64) NOT NULL,
  `note` varchar(50) DEFAULT NULL,
  `forced_variant` int(11) DEFAULT NULL,
  `mobile_allowed` boolean NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=cp1250 COLLATE=cp1250_czech_cs;

-- --------------------------------------------------------

--
-- Table structure for table `request_logs`
--
CREATE TABLE `request_logs` (
  `id` int(11) NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `api_key_id` int(11) NOT NULL,
  `endpoint` varchar(255) NOT NULL,
  `method` varchar(10) NOT NULL,
  `headers` text NOT NULL,
  `query_params` text NOT NULL,
  `request_body` text NOT NULL,
  `response_body` mediumtext NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=cp1250 COLLATE=cp1250_czech_cs;

-- --------------------------------------------------------

--
-- Table structure for table `trials`
--
CREATE TABLE `trials` (
  `id` int(11) NOT NULL,
  `api_key_id` int(11) NOT NULL,
  `variant` int(11) NOT NULL,
  `results` longtext NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `api_keys`
--
ALTER TABLE `api_keys`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `key_value` (`key_value`);

--
-- Indexes for table `request_logs`
--
ALTER TABLE `request_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `trials`
--
ALTER TABLE `trials`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `api_keys`
--
ALTER TABLE `api_keys`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `request_logs`
--
ALTER TABLE `request_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `trials`
--
ALTER TABLE `trials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
COMMIT;
