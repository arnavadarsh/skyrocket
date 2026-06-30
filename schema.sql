-- ============================================================
-- SkyConnect flight_booking — canonical schema (Week 1)
-- Single source of truth. Import with:
--   mysql -u <user> -p < schema.sql
-- Safe to re-run: drops and recreates the database.
-- Seed dates are relative to CURDATE() so demo searches always
-- find flights in the next 1–8 weeks.
--
-- Seed accounts:
--   admin / Admin@123  (administrator)
--   demo  / Demo@123   (regular user)
-- ============================================================

DROP DATABASE IF EXISTS flight_booking;
CREATE DATABASE flight_booking CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE flight_booking;

-- ------------------------------------------------------------
-- users
-- ------------------------------------------------------------
CREATE TABLE users (
  user_id    INT NOT NULL AUTO_INCREMENT,
  username   VARCHAR(50)  NOT NULL,
  email      VARCHAR(100) NOT NULL,
  password   VARCHAR(255) NOT NULL,
  first_name VARCHAR(50)  NOT NULL,
  last_name  VARCHAR(50)  NOT NULL,
  role       ENUM('user','staff','admin') NOT NULL DEFAULT 'user',
  created_at TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- bcrypt hashes generated with password_hash(..., PASSWORD_DEFAULT)
-- admin/Admin@123, staff/Staff@123, demo/Demo@123
INSERT INTO users (user_id, username, email, password, first_name, last_name, role) VALUES
(1, 'admin', 'admin@skyconnect.local', '$2y$12$AJYHFUDZ0EzTDgGBqXLNNuF2Te8W2AArOdU/73KXgUYl80IqP8svC', 'Admin', 'User', 'admin'),
(2, 'staff', 'staff@skyconnect.local', '$2y$12$WCXXrBLi1pK7d3SWgrzSu.z1aflDh.ybm/F6/X.TNzDl2feLIftTe', 'Sam', 'Staffer', 'staff'),
(3, 'demo',  'demo@skyconnect.local',  '$2y$12$FCYhZLEzkfjL0zfqsotVKeVbNzV044a.az9Vl.QsfN0g9nYSzkw7C', 'Demo', 'Traveler', 'user');

-- ------------------------------------------------------------
-- aircraft
-- (capacity vs. flight seats is a cross-table rule -> PHP-validated)
-- ------------------------------------------------------------
CREATE TABLE aircraft (
  aircraft_id        INT NOT NULL AUTO_INCREMENT,
  model              VARCHAR(50) NOT NULL,
  capacity           INT NOT NULL,
  maintenance_status ENUM('active','maintenance','retired') NOT NULL DEFAULT 'active',
  PRIMARY KEY (aircraft_id),
  CONSTRAINT chk_aircraft_capacity CHECK (capacity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO aircraft (aircraft_id, model, capacity, maintenance_status) VALUES
(1,  'Airbus A320neo',   180, 'active'),
(2,  'Airbus A320neo',   180, 'active'),
(3,  'Boeing 737-800',   189, 'active'),
(4,  'Boeing 737 MAX 8', 178, 'active'),
(5,  'Airbus A321neo',   220, 'active'),
(6,  'Airbus A321neo',   220, 'active'),
(7,  'Boeing 777-300ER', 300, 'active'),
(8,  'Boeing 777-200LR', 280, 'active'),
(9,  'Airbus A350-900',  300, 'active'),
(10, 'Airbus A350-900',  300, 'active'),
(11, 'Boeing 787-8',     248, 'active'),
(12, 'Airbus A330-300',  277, 'active'),
(13, 'Boeing 737-800',   189, 'maintenance'),
(14, 'Airbus A320neo',   180, 'retired');

-- ------------------------------------------------------------
-- gates
-- ------------------------------------------------------------
CREATE TABLE gates (
  gate_id     INT NOT NULL AUTO_INCREMENT,
  terminal    ENUM('T1','T2','T3') NOT NULL,
  gate_number VARCHAR(4) NOT NULL,
  status      ENUM('open','closed') NOT NULL DEFAULT 'open',
  PRIMARY KEY (gate_id),
  UNIQUE KEY uq_gates_terminal_number (terminal, gate_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO gates (gate_id, terminal, gate_number, status) VALUES
(1,  'T1', '1',  'open'),
(2,  'T1', '2',  'open'),
(3,  'T1', '3',  'closed'),
(4,  'T1', '4',  'open'),
(5,  'T2', '10', 'open'),
(6,  'T2', '11', 'open'),
(7,  'T2', '12', 'open'),
(8,  'T2', '14', 'open'),
(9,  'T3', '21', 'open'),
(10, 'T3', '22', 'open'),
(11, 'T3', '23', 'open'),
(12, 'T3', '24', 'closed');

-- ------------------------------------------------------------
-- flights
-- (no `price` column: economy_price is the canonical base fare)
-- ------------------------------------------------------------
CREATE TABLE flights (
  flight_id       INT NOT NULL AUTO_INCREMENT,
  flight_number   VARCHAR(10) NOT NULL,
  airline         VARCHAR(50) NOT NULL,
  departure_city  VARCHAR(50) NOT NULL,
  arrival_city    VARCHAR(50) NOT NULL,
  departure_time  DATETIME NOT NULL,
  arrival_time    DATETIME NOT NULL,
  available_seats INT NOT NULL,
  economy_price   DECIMAL(10,2) NOT NULL,
  business_price  DECIMAL(10,2) NOT NULL,
  first_price     DECIMAL(10,2) NOT NULL,
  status ENUM('scheduled','delayed','boarding','departed','arrived','cancelled') NOT NULL DEFAULT 'scheduled',
  aircraft_id INT DEFAULT NULL,
  gate_id     INT DEFAULT NULL,
  PRIMARY KEY (flight_id),
  UNIQUE KEY uq_flights_number_departure (flight_number, departure_time),
  KEY idx_flights_aircraft (aircraft_id),
  KEY idx_flights_gate (gate_id),
  -- Speeds route+date searches: equality columns first, range column last
  KEY idx_flights_route_time (departure_city, arrival_city, departure_time),
  CONSTRAINT fk_flights_aircraft FOREIGN KEY (aircraft_id) REFERENCES aircraft (aircraft_id) ON DELETE RESTRICT,
  CONSTRAINT fk_flights_gate     FOREIGN KEY (gate_id)     REFERENCES gates (gate_id)        ON DELETE SET NULL,
  CONSTRAINT chk_flights_times  CHECK (arrival_time > departure_time),
  CONSTRAINT chk_flights_seats  CHECK (available_seats >= 0),
  CONSTRAINT chk_flights_prices CHECK (economy_price > 0 AND business_price > 0 AND first_price > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO flights (flight_id, flight_number, airline, departure_city, arrival_city, departure_time, arrival_time, available_seats, economy_price, business_price, first_price) VALUES
(1, 'SK101', 'SkyConnect', 'New York', 'London', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 9 DAY), '08:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 9 DAY), '08:00:00') + INTERVAL 720 MINUTE, 150, 38700.00, 69660.00, 96750.00),
(2, 'SK102', 'SkyConnect', 'London', 'New York', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 13 DAY), '10:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 13 DAY), '10:00:00') + INTERVAL 720 MINUTE, 150, 41280.00, 74304.00, 103200.00),
(3, 'SK203', 'SkyConnect', 'Mumbai', 'Delhi', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 11 DAY), '06:30:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 11 DAY), '06:30:00') + INTERVAL 150 MINUTE, 180, 10320.00, 18576.00, 25800.00),
(4, 'SK204', 'SkyConnect', 'Delhi', 'Mumbai', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 14 DAY), '18:30:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 14 DAY), '18:30:00') + INTERVAL 150 MINUTE, 180, 9460.00, 17028.00, 23650.00),
(5, 'FL001', 'AirwaysX', 'Paris', 'Tokyo', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 23 DAY), '13:14:04'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 23 DAY), '13:14:04') + INTERVAL 600 MINUTE, 100, 53928.88, 97071.64, 134822.20),
(6, 'FL002', 'JetStream', 'Singapore', 'Los Angeles', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 17 DAY), '23:14:04'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 17 DAY), '23:14:04') + INTERVAL 300 MINUTE, 180, 51152.80, 92075.04, 127882.00),
(7, 'FL003', 'AirwaysX', 'Mumbai', 'Sydney', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 14 DAY), '06:14:04'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 14 DAY), '06:14:04') + INTERVAL 660 MINUTE, 200, 22262.82, 40073.42, 55657.48),
(8, 'FL004', 'AirwaysX', 'Los Angeles', 'Dubai', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 9 DAY), '06:14:04'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 9 DAY), '06:14:04') + INTERVAL 660 MINUTE, 90, 84161.32, 151490.72, 210403.30),
(9, 'FL005', 'GlobalFly', 'Tokyo', 'Sydney', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 13 DAY), '13:14:04'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 13 DAY), '13:14:04') + INTERVAL 540 MINUTE, 130, 77443.00, 139397.40, 193607.50),
(10, 'FL006', 'SkyLine', 'New York', 'London', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 18 DAY), '09:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 18 DAY), '09:00:00') + INTERVAL 720 MINUTE, 150, 61963.00, 111533.40, 154907.50),
(11, 'FL007', 'JetStream', 'London', 'Berlin', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 18 DAY), '12:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 18 DAY), '12:00:00') + INTERVAL 120 MINUTE, 100, 12964.50, 23336.10, 32411.68),
(12, 'FL008', 'AirNova', 'Dubai', 'Singapore', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 19 DAY), '18:30:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 19 DAY), '18:30:00') + INTERVAL 480 MINUTE, 190, 43877.20, 78978.96, 109693.00),
(13, 'FL009', 'AirNova', 'Sydney', 'Paris', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 20 DAY), '22:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 20 DAY), '22:00:00') + INTERVAL 960 MINUTE, 80, 76595.90, 137872.62, 191490.18),
(14, 'FL010', 'GlobalFly', 'Berlin', 'Rome', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 21 DAY), '06:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 21 DAY), '06:00:00') + INTERVAL 120 MINUTE, 60, 10397.40, 18715.32, 25993.50),
(15, 'FL011', 'SkyLine', 'Rome', 'Madrid', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 22 DAY), '10:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 22 DAY), '10:00:00') + INTERVAL 120 MINUTE, 90, 11631.50, 20936.70, 29079.18),
(16, 'FL012', 'JetStream', 'Toronto', 'New York', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 22 DAY), '08:30:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 22 DAY), '08:30:00') + INTERVAL 90 MINUTE, 100, 17200.00, 30960.00, 43000.00),
(17, 'FL013', 'AirwaysX', 'Bangkok', 'Delhi', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 23 DAY), '05:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 23 DAY), '05:00:00') + INTERVAL 150 MINUTE, 120, 15514.40, 27925.92, 38786.00),
(18, 'FL014', 'AirNova', 'Delhi', 'Kuala Lumpur', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 24 DAY), '13:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 24 DAY), '13:00:00') + INTERVAL 300 MINUTE, 110, 27588.80, 49659.84, 68972.00),
(19, 'FL015', 'GlobalFly', 'Tokyo', 'Bangkok', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 25 DAY), '07:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 25 DAY), '07:00:00') + INTERVAL 180 MINUTE, 100, 36980.00, 66564.00, 92450.00),
(20, 'FL016', 'SkyLine', 'Madrid', 'Lisbon', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 25 DAY), '06:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 25 DAY), '06:00:00') + INTERVAL 90 MINUTE, 80, 7783.00, 14009.40, 19457.50),
(21, 'FL017', 'JetStream', 'Lisbon', 'Amsterdam', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 26 DAY), '09:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 26 DAY), '09:00:00') + INTERVAL 180 MINUTE, 90, 13811.60, 24860.88, 34529.00),
(22, 'FL018', 'AirwaysX', 'Amsterdam', 'Frankfurt', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 27 DAY), '10:30:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 27 DAY), '10:30:00') + INTERVAL 75 MINUTE, 130, 6897.20, 12414.96, 17243.00),
(23, 'FL019', 'SkyLine', 'Frankfurt', 'Zurich', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 28 DAY), '11:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 28 DAY), '11:00:00') + INTERVAL 75 MINUTE, 90, 8600.00, 15480.00, 21500.00),
(24, 'FL020', 'JetStream', 'Zurich', 'Vienna', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 28 DAY), '14:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 28 DAY), '14:00:00') + INTERVAL 90 MINUTE, 120, 9524.50, 17144.10, 23811.68),
(25, 'FL021', 'AirNova', 'Vienna', 'Prague', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 29 DAY), '08:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 29 DAY), '08:00:00') + INTERVAL 75 MINUTE, 100, 8213.00, 14783.40, 20532.50),
(26, 'FL022', 'GlobalFly', 'Prague', 'Budapest', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 30 DAY), '10:30:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 30 DAY), '10:30:00') + INTERVAL 75 MINUTE, 110, 7310.00, 13158.00, 18275.00),
(27, 'FL023', 'AirwaysX', 'Budapest', 'Warsaw', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 31 DAY), '06:30:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 31 DAY), '06:30:00') + INTERVAL 90 MINUTE, 120, 7739.14, 13930.28, 19348.28),
(28, 'FL024', 'SkyLine', 'Warsaw', 'Helsinki', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 32 DAY), '15:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 32 DAY), '15:00:00') + INTERVAL 180 MINUTE, 100, 12521.60, 22538.88, 31304.00),
(29, 'FL025', 'JetStream', 'Helsinki', 'Oslo', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 32 DAY), '12:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 32 DAY), '12:00:00') + INTERVAL 90 MINUTE, 110, 11197.20, 20154.96, 27993.00),
(30, 'FL026', 'AirNova', 'Oslo', 'Stockholm', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 33 DAY), '09:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 33 DAY), '09:00:00') + INTERVAL 60 MINUTE, 100, 8204.40, 14767.92, 20511.00),
(31, 'FL027', 'GlobalFly', 'Stockholm', 'Copenhagen', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 34 DAY), '08:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 34 DAY), '08:00:00') + INTERVAL 75 MINUTE, 90, 9030.00, 16254.00, 22575.00),
(32, 'FL028', 'AirwaysX', 'Copenhagen', 'Reykjavik', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 35 DAY), '10:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 35 DAY), '10:00:00') + INTERVAL 210 MINUTE, 90, 19844.50, 35720.10, 49611.68),
(33, 'FL029', 'SkyLine', 'Reykjavik', 'Dublin', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 35 DAY), '11:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 35 DAY), '11:00:00') + INTERVAL 210 MINUTE, 70, 21070.00, 37926.00, 52675.00),
(34, 'FL030', 'JetStream', 'Dublin', 'New York', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 36 DAY), '15:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 36 DAY), '15:00:00') + INTERVAL 360 MINUTE, 140, 51625.80, 92926.44, 129064.50),
(35, 'FL031', 'AirNova', 'New York', 'Chicago', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 37 DAY), '08:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 37 DAY), '08:00:00') + INTERVAL 150 MINUTE, 160, 18103.00, 32585.40, 45257.50),
(36, 'FL032', 'GlobalFly', 'Chicago', 'San Francisco', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 38 DAY), '11:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 38 DAY), '11:00:00') + INTERVAL 270 MINUTE, 180, 30100.00, 54180.00, 75250.00),
(37, 'FL033', 'AirwaysX', 'San Francisco', 'Los Angeles', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 38 DAY), '07:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 38 DAY), '07:00:00') + INTERVAL 90 MINUTE, 200, 10320.00, 18576.00, 25800.00),
(38, 'FL034', 'SkyLine', 'Los Angeles', 'Las Vegas', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 39 DAY), '12:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 39 DAY), '12:00:00') + INTERVAL 75 MINUTE, 150, 7774.40, 13993.92, 19436.00),
(39, 'FL035', 'JetStream', 'Las Vegas', 'Seattle', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 40 DAY), '06:30:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 40 DAY), '06:30:00') + INTERVAL 150 MINUTE, 170, 15050.00, 27090.00, 37625.00),
(40, 'FL036', 'AirNova', 'Seattle', 'Vancouver', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 41 DAY), '08:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 41 DAY), '08:00:00') + INTERVAL 90 MINUTE, 180, 11627.20, 20928.96, 29068.00),
(41, 'FL037', 'GlobalFly', 'Vancouver', 'Calgary', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 41 DAY), '14:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 41 DAY), '14:00:00') + INTERVAL 120 MINUTE, 140, 12108.80, 21795.84, 30272.00),
(42, 'FL038', 'AirwaysX', 'Calgary', 'Toronto', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 42 DAY), '09:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 42 DAY), '09:00:00') + INTERVAL 240 MINUTE, 160, 22360.00, 40248.00, 55900.00),
(43, 'FL039', 'SkyLine', 'Toronto', 'Montreal', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 43 DAY), '11:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 43 DAY), '11:00:00') + INTERVAL 90 MINUTE, 170, 12040.00, 21672.00, 30100.00),
(44, 'FL040', 'JetStream', 'Montreal', 'Boston', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 44 DAY), '13:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 44 DAY), '13:00:00') + INTERVAL 120 MINUTE, 120, 13824.50, 24884.10, 34561.68),
(45, 'FL041', 'AirNova', 'Boston', 'Atlanta', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 45 DAY), '06:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 45 DAY), '06:00:00') + INTERVAL 150 MINUTE, 130, 19780.00, 35604.00, 49450.00),
(46, 'FL042', 'GlobalFly', 'Atlanta', 'Miami', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 45 DAY), '10:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 45 DAY), '10:00:00') + INTERVAL 120 MINUTE, 150, 18103.00, 32585.40, 45257.50),
(47, 'FL043', 'AirwaysX', 'Miami', 'Havana', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 46 DAY), '09:30:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 46 DAY), '09:30:00') + INTERVAL 60 MINUTE, 140, 16391.60, 29504.88, 40979.00),
(48, 'FL044', 'SkyLine', 'Havana', 'Panama City', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 47 DAY), '12:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 47 DAY), '12:00:00') + INTERVAL 120 MINUTE, 100, 18920.00, 34056.00, 47300.00),
(49, 'FL045', 'JetStream', 'Panama City', 'Lima', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 48 DAY), '06:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 48 DAY), '06:00:00') + INTERVAL 240 MINUTE, 90, 29308.80, 52755.84, 73272.00),
(50, 'FL046', 'AirNova', 'Lima', 'Santiago', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 48 DAY), '08:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 48 DAY), '08:00:00') + INTERVAL 210 MINUTE, 80, 26681.50, 48026.70, 66704.18),
(51, 'FL047', 'GlobalFly', 'Santiago', 'Buenos Aires', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 49 DAY), '07:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 49 DAY), '07:00:00') + INTERVAL 150 MINUTE, 90, 25370.00, 45666.00, 63425.00),
(52, 'FL048', 'AirwaysX', 'Buenos Aires', 'Rio de Janeiro', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 50 DAY), '10:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 50 DAY), '10:00:00') + INTERVAL 180 MINUTE, 100, 28440.20, 51192.36, 71100.50),
(53, 'FL049', 'SkyLine', 'Rio de Janeiro', 'Sao Paulo', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 51 DAY), '06:30:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 51 DAY), '06:30:00') + INTERVAL 75 MINUTE, 110, 10320.00, 18576.00, 25800.00),
(54, 'FL050', 'JetStream', 'Sao Paulo', 'Cape Town', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 51 DAY), '08:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 51 DAY), '08:00:00') + INTERVAL 540 MINUTE, 100, 65411.60, 117740.88, 163529.00),
(55, 'FL051', 'AirNova', 'Cape Town', 'Nairobi', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 52 DAY), '07:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 52 DAY), '07:00:00') + INTERVAL 240 MINUTE, 120, 37840.00, 68112.00, 94600.00),
(56, 'FL052', 'GlobalFly', 'Nairobi', 'Dubai', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 53 DAY), '10:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 53 DAY), '10:00:00') + INTERVAL 480 MINUTE, 130, 51621.50, 92918.70, 129054.18),
(57, 'FL053', 'AirwaysX', 'Dubai', 'Mumbai', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 54 DAY), '03:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 54 DAY), '03:00:00') + INTERVAL 180 MINUTE, 150, 27554.40, 49597.92, 68886.00),
(58, 'FL054', 'SkyLine', 'Mumbai', 'Bangalore', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 54 DAY), '05:30:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 54 DAY), '05:30:00') + INTERVAL 90 MINUTE, 160, 12943.00, 23297.40, 32357.50),
(59, 'FL055', 'JetStream', 'Bangalore', 'Chennai', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 55 DAY), '06:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 55 DAY), '06:00:00') + INTERVAL 75 MINUTE, 170, 12057.20, 21702.96, 30143.00),
(60, 'SK201', 'SkyConnect', 'New York', 'London', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 18 DAY), '08:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 18 DAY), '08:00:00') + INTERVAL 720 MINUTE, 120, 44720.00, 80496.00, 111800.00),
(61, 'SK202', 'SkyConnect', 'London', 'New York', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 18 DAY), '10:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 18 DAY), '10:00:00') + INTERVAL 720 MINUTE, 110, 46440.00, 83592.00, 116100.00),
(63, 'SK260', 'SkyConnect', 'Rome', 'Paris', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 20 DAY), '14:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 20 DAY), '14:00:00') + INTERVAL 120 MINUTE, 100, 15050.00, 27090.00, 37625.00),
(64, 'SK205', 'SkyConnect', 'Tokyo', 'Sydney', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 21 DAY), '07:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 21 DAY), '07:00:00') + INTERVAL 600 MINUTE, 150, 60200.00, 108360.00, 150500.00),
(65, 'SK206', 'SkyConnect', 'Sydney', 'Tokyo', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 22 DAY), '12:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 22 DAY), '12:00:00') + INTERVAL 600 MINUTE, 140, 61060.00, 109908.00, 152650.00),
(66, 'SK207', 'SkyConnect', 'Dubai', 'Mumbai', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 22 DAY), '06:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 22 DAY), '06:00:00') + INTERVAL 150 MINUTE, 100, 18920.00, 34056.00, 47300.00),
(67, 'SK208', 'SkyConnect', 'Mumbai', 'Dubai', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 23 DAY), '09:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 23 DAY), '09:00:00') + INTERVAL 150 MINUTE, 110, 19350.00, 34830.00, 48375.00),
(68, 'SK209', 'SkyConnect', 'Los Angeles', 'San Francisco', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 24 DAY), '13:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 24 DAY), '13:00:00') + INTERVAL 90 MINUTE, 80, 10320.00, 18576.00, 25800.00),
(69, 'SK210', 'SkyConnect', 'San Francisco', 'Los Angeles', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 25 DAY), '15:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 25 DAY), '15:00:00') + INTERVAL 90 MINUTE, 90, 10750.00, 19350.00, 26875.00),
(70, 'SK211', 'SkyConnect', 'Berlin', 'Amsterdam', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 25 DAY), '08:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 25 DAY), '08:00:00') + INTERVAL 120 MINUTE, 70, 13760.00, 24768.00, 34400.00),
(71, 'SK212', 'SkyConnect', 'Amsterdam', 'Berlin', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 26 DAY), '11:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 26 DAY), '11:00:00') + INTERVAL 120 MINUTE, 80, 14190.00, 25542.00, 35475.00),
(72, 'SK213', 'SkyConnect', 'Delhi', 'Singapore', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 27 DAY), '18:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 27 DAY), '18:00:00') + INTERVAL 300 MINUTE, 130, 30100.00, 54180.00, 75250.00),
(73, 'SK214', 'SkyConnect', 'Singapore', 'Delhi', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 28 DAY), '20:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 28 DAY), '20:00:00') + INTERVAL 300 MINUTE, 120, 30530.00, 54954.00, 76325.00),
(74, 'SK215', 'SkyConnect', 'Bangkok', 'Hong Kong', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 28 DAY), '07:30:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 28 DAY), '07:30:00') + INTERVAL 240 MINUTE, 90, 18060.00, 32508.00, 45150.00),
(75, 'SK216', 'SkyConnect', 'Hong Kong', 'Bangkok', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 29 DAY), '13:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 29 DAY), '13:00:00') + INTERVAL 240 MINUTE, 100, 18490.00, 33282.00, 46225.00),
(76, 'SK217', 'SkyConnect', 'Chicago', 'Toronto', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 30 DAY), '10:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 30 DAY), '10:00:00') + INTERVAL 120 MINUTE, 100, 17200.00, 30960.00, 43000.00),
(77, 'SK218', 'SkyConnect', 'Toronto', 'Chicago', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 31 DAY), '14:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 31 DAY), '14:00:00') + INTERVAL 120 MINUTE, 110, 17630.00, 31734.00, 44075.00),
(78, 'SK219', 'SkyConnect', 'Madrid', 'Barcelona', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 32 DAY), '09:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 32 DAY), '09:00:00') + INTERVAL 90 MINUTE, 60, 8170.00, 14706.00, 20425.00),
(79, 'SK220', 'SkyConnect', 'Barcelona', 'Madrid', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 32 DAY), '11:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 32 DAY), '11:00:00') + INTERVAL 90 MINUTE, 70, 8600.00, 15480.00, 21500.00),
(80, 'SK221', 'SkyConnect', 'Istanbul', 'Athens', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 33 DAY), '08:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 33 DAY), '08:00:00') + INTERVAL 90 MINUTE, 70, 11180.00, 20124.00, 27950.00),
(81, 'SK222', 'SkyConnect', 'Athens', 'Istanbul', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 34 DAY), '10:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 34 DAY), '10:00:00') + INTERVAL 90 MINUTE, 80, 11610.00, 20898.00, 29025.00),
(82, 'SK223', 'SkyConnect', 'Beijing', 'Shanghai', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 35 DAY), '07:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 35 DAY), '07:00:00') + INTERVAL 120 MINUTE, 80, 12900.00, 23220.00, 32250.00),
(83, 'SK224', 'SkyConnect', 'Shanghai', 'Beijing', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 35 DAY), '10:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 35 DAY), '10:00:00') + INTERVAL 120 MINUTE, 90, 13330.00, 23994.00, 33325.00),
(84, 'SK225', 'SkyConnect', 'Cape Town', 'Johannesburg', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 36 DAY), '13:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 36 DAY), '13:00:00') + INTERVAL 120 MINUTE, 90, 15480.00, 27864.00, 38700.00),
(85, 'SK226', 'SkyConnect', 'Johannesburg', 'Cape Town', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 37 DAY), '16:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 37 DAY), '16:00:00') + INTERVAL 120 MINUTE, 100, 15910.00, 28638.00, 39775.00),
(86, 'SK227', 'SkyConnect', 'Sydney', 'Melbourne', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 38 DAY), '09:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 38 DAY), '09:00:00') + INTERVAL 90 MINUTE, 70, 9460.00, 17028.00, 23650.00),
(87, 'SK228', 'SkyConnect', 'Melbourne', 'Sydney', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 38 DAY), '11:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 38 DAY), '11:00:00') + INTERVAL 90 MINUTE, 80, 9890.00, 17802.00, 24725.00),
(88, 'SK229', 'SkyConnect', 'Dubai', 'London', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 39 DAY), '06:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 39 DAY), '06:00:00') + INTERVAL 360 MINUTE, 120, 51600.00, 92880.00, 129000.00),
(89, 'SK230', 'SkyConnect', 'London', 'Dubai', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 40 DAY), '14:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 40 DAY), '14:00:00') + INTERVAL 360 MINUTE, 110, 52460.00, 94428.00, 131150.00),
(90, 'SK231', 'SkyConnect', 'New York', 'Paris', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 41 DAY), '08:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 41 DAY), '08:00:00') + INTERVAL 720 MINUTE, 120, 45580.00, 82044.00, 113950.00),
(91, 'SK232', 'SkyConnect', 'Paris', 'New York', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 42 DAY), '10:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 42 DAY), '10:00:00') + INTERVAL 720 MINUTE, 110, 46440.00, 83592.00, 116100.00),
(92, 'SK233', 'SkyConnect', 'Los Angeles', 'Tokyo', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 43 DAY), '07:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 43 DAY), '07:00:00') + INTERVAL 720 MINUTE, 150, 68800.00, 123840.00, 172000.00),
(93, 'SK234', 'SkyConnect', 'Tokyo', 'Los Angeles', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 44 DAY), '12:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 44 DAY), '12:00:00') + INTERVAL 660 MINUTE, 140, 69660.00, 125388.00, 174150.00),
(94, 'SK235', 'SkyConnect', 'Singapore', 'Bangkok', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 45 DAY), '09:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 45 DAY), '09:00:00') + INTERVAL 120 MINUTE, 80, 14620.00, 26316.00, 36550.00),
(95, 'SK236', 'SkyConnect', 'Bangkok', 'Singapore', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 45 DAY), '13:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 45 DAY), '13:00:00') + INTERVAL 120 MINUTE, 90, 15050.00, 27090.00, 37625.00),
(96, 'SK237', 'SkyConnect', 'Delhi', 'London', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 46 DAY), '18:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 46 DAY), '18:00:00') + INTERVAL 480 MINUTE, 130, 55900.00, 100620.00, 139750.00),
(97, 'SK238', 'SkyConnect', 'London', 'Delhi', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 47 DAY), '20:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 47 DAY), '20:00:00') + INTERVAL 480 MINUTE, 120, 56330.00, 101394.00, 140825.00),
(98, 'SK239', 'SkyConnect', 'San Francisco', 'Chicago', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 48 DAY), '10:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 48 DAY), '10:00:00') + INTERVAL 240 MINUTE, 100, 25800.00, 46440.00, 64500.00),
(99, 'SK240', 'SkyConnect', 'Chicago', 'San Francisco', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 48 DAY), '15:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 48 DAY), '15:00:00') + INTERVAL 240 MINUTE, 110, 26230.00, 47214.00, 65575.00),
(100, 'SK241', 'SkyConnect', 'Rome', 'Berlin', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 49 DAY), '08:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 49 DAY), '08:00:00') + INTERVAL 120 MINUTE, 70, 13760.00, 24768.00, 34400.00),
(101, 'SK242', 'SkyConnect', 'Berlin', 'Rome', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 50 DAY), '11:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 50 DAY), '11:00:00') + INTERVAL 120 MINUTE, 80, 14190.00, 25542.00, 35475.00),
(102, 'SK243', 'SkyConnect', 'Toronto', 'Vancouver', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 51 DAY), '09:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 51 DAY), '09:00:00') + INTERVAL 180 MINUTE, 90, 30100.00, 54180.00, 75250.00),
(103, 'SK244', 'SkyConnect', 'Vancouver', 'Toronto', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 51 DAY), '13:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 51 DAY), '13:00:00') + INTERVAL 180 MINUTE, 100, 30530.00, 54954.00, 76325.00),
(104, 'SK245', 'SkyConnect', 'Madrid', 'Lisbon', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 52 DAY), '07:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 52 DAY), '07:00:00') + INTERVAL 90 MINUTE, 60, 10320.00, 18576.00, 25800.00),
(105, 'SK246', 'SkyConnect', 'Lisbon', 'Madrid', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 53 DAY), '09:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 53 DAY), '09:00:00') + INTERVAL 90 MINUTE, 70, 10750.00, 19350.00, 26875.00),
(106, 'SK247', 'SkyConnect', 'Istanbul', 'Dubai', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 54 DAY), '06:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 54 DAY), '06:00:00') + INTERVAL 360 MINUTE, 120, 34400.00, 61920.00, 86000.00),
(107, 'SK248', 'SkyConnect', 'Dubai', 'Istanbul', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 54 DAY), '14:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 54 DAY), '14:00:00') + INTERVAL 360 MINUTE, 110, 35260.00, 63468.00, 88150.00),
(108, 'SK249', 'SkyConnect', 'Beijing', 'Hong Kong', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 55 DAY), '08:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 55 DAY), '08:00:00') + INTERVAL 240 MINUTE, 80, 21500.00, 38700.00, 53750.00),
(109, 'SK250', 'SkyConnect', 'Hong Kong', 'Beijing', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 56 DAY), '13:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 56 DAY), '13:00:00') + INTERVAL 240 MINUTE, 90, 21930.00, 39474.00, 54825.00),
(110, 'AI213', 'Air India', 'Delhi', 'Mumbai', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 7 DAY), '06:30:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 7 DAY), '06:30:00') + INTERVAL 135 MINUTE, 120, 15093.00, 27167.40, 37732.50),
(111, 'IG345', 'IndiGo', 'Delhi', 'Mumbai', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 7 DAY), '08:15:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 7 DAY), '08:15:00') + INTERVAL 135 MINUTE, 180, 12534.50, 22562.10, 31336.68),
(112, 'SJ789', 'SpiceJet', 'Delhi', 'Mumbai', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 7 DAY), '10:45:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 7 DAY), '10:45:00') + INTERVAL 135 MINUTE, 150, 11373.50, 20472.30, 28434.18),
(113, 'VT456', 'Vistara', 'Delhi', 'Mumbai', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 7 DAY), '12:30:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 7 DAY), '12:30:00') + INTERVAL 135 MINUTE, 110, 16770.00, 30186.00, 41925.00),
(114, 'SK512', 'SkyConnect', 'Delhi', 'Mumbai', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 7 DAY), '14:15:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 7 DAY), '14:15:00') + INTERVAL 135 MINUTE, 140, 14233.00, 25619.40, 35582.50),
(115, 'GA678', 'GoAir', 'Delhi', 'Mumbai', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 7 DAY), '16:45:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 7 DAY), '16:45:00') + INTERVAL 135 MINUTE, 160, 11072.50, 19930.50, 27681.68),
(116, 'AA234', 'AirAsia', 'Delhi', 'Mumbai', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 7 DAY), '18:30:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 7 DAY), '18:30:00') + INTERVAL 135 MINUTE, 170, 11975.50, 21555.90, 29939.18),
(117, 'JA567', 'Jet Airways', 'Delhi', 'Mumbai', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 7 DAY), '21:00:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 7 DAY), '21:00:00') + INTERVAL 135 MINUTE, 130, 15953.00, 28715.40, 39882.50),
(118, 'AI214', 'Air India', 'Delhi', 'Mumbai', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 8 DAY), '05:45:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 8 DAY), '05:45:00') + INTERVAL 135 MINUTE, 120, 15716.50, 28289.70, 39291.68),
(119, 'IG346', 'IndiGo', 'Delhi', 'Mumbai', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 8 DAY), '07:30:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 8 DAY), '07:30:00') + INTERVAL 135 MINUTE, 180, 13115.00, 23607.00, 32787.50),
(120, 'SJ790', 'SpiceJet', 'Delhi', 'Mumbai', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 8 DAY), '09:15:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 8 DAY), '09:15:00') + INTERVAL 135 MINUTE, 150, 11889.50, 21401.10, 29724.18),
(121, 'VT457', 'Vistara', 'Delhi', 'Mumbai', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 8 DAY), '11:45:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 8 DAY), '11:45:00') + INTERVAL 135 MINUTE, 110, 17630.00, 31734.00, 44075.00),
(122, 'SK513', 'SkyConnect', 'Delhi', 'Mumbai', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 8 DAY), '13:30:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 8 DAY), '13:30:00') + INTERVAL 135 MINUTE, 140, 14835.00, 26703.00, 37087.50),
(123, 'GA679', 'GoAir', 'Delhi', 'Mumbai', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 8 DAY), '15:15:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 8 DAY), '15:15:00') + INTERVAL 135 MINUTE, 160, 11588.50, 20859.30, 28971.68),
(124, 'AA235', 'AirAsia', 'Delhi', 'Mumbai', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 8 DAY), '17:45:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 8 DAY), '17:45:00') + INTERVAL 135 MINUTE, 170, 12233.50, 22020.30, 30584.18),
(125, 'JA568', 'Jet Airways', 'Delhi', 'Mumbai', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 8 DAY), '20:15:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 8 DAY), '20:15:00') + INTERVAL 135 MINUTE, 130, 16555.00, 29799.00, 41387.50),
(126, 'AI219', 'Air India', 'Kolkata', 'Delhi', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 9 DAY), '04:35:00'), TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 9 DAY), '04:35:00') + INTERVAL 180 MINUTE, 150, 9460.00, 28982.00, 34400.00);

-- Every flight gets an active aircraft whose capacity covers its seeded
-- seats (seat counts range 60-200), spread across the fleet by flight_id
UPDATE flights SET aircraft_id =
  CASE
    WHEN available_seats <= 178 THEN ELT(1 + (flight_id MOD 3), 1, 2, 4)
    WHEN available_seats <= 180 THEN ELT(1 + (flight_id MOD 2), 1, 2)
    WHEN available_seats <= 189 THEN 3
    ELSE ELT(1 + (flight_id MOD 2), 5, 6)
  END;

-- Gates for roughly half the flights (open gates only: 3 and 12 are
-- closed); the rest stay NULL for staff to assign in the demo
UPDATE flights SET gate_id = ELT(1 + (flight_id MOD 10), 1, 2, 4, 5, 6, 7, 8, 9, 10, 11)
WHERE flight_id MOD 2 = 0;

-- Staff dashboard demo data: the board shows departures within 48h,
-- so pull the Delhi->Mumbai block (110-117) to tomorrow and two
-- flights (118-119) to today. Times of day and durations preserved.
-- (Assignments evaluate left to right: arrival_time computes from the
-- old departure_time before departure_time itself is overwritten.)
UPDATE flights
SET arrival_time   = TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 1 DAY), TIME(departure_time))
                     + INTERVAL TIMESTAMPDIFF(MINUTE, departure_time, arrival_time) MINUTE,
    departure_time = TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 1 DAY), TIME(departure_time))
WHERE flight_id BETWEEN 110 AND 117;

UPDATE flights
SET arrival_time   = TIMESTAMP(CURDATE(), TIME(departure_time))
                     + INTERVAL TIMESTAMPDIFF(MINUTE, departure_time, arrival_time) MINUTE,
    departure_time = TIMESTAMP(CURDATE(), TIME(departure_time))
WHERE flight_id IN (118, 119);

-- ------------------------------------------------------------
-- bookings
-- (no `is_round_trip`: derive it from return_flight_id IS NOT NULL)
-- Seeded empty: seat counts above are full capacity.
-- ------------------------------------------------------------
CREATE TABLE bookings (
  booking_id       INT NOT NULL AUTO_INCREMENT,
  user_id          INT NOT NULL,
  flight_id        INT NOT NULL,
  return_flight_id INT DEFAULT NULL,
  booking_date     TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  passenger_count  INT NOT NULL DEFAULT 1,
  total_price      DECIMAL(10,2) NOT NULL,
  class  ENUM('economy','business','first')      NOT NULL DEFAULT 'economy',
  -- a booking is 'pending' until its payment completes
  status ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending',
  PRIMARY KEY (booking_id),
  KEY idx_bookings_user (user_id),
  KEY idx_bookings_flight (flight_id),
  KEY idx_bookings_return_flight (return_flight_id),
  CONSTRAINT fk_bookings_user   FOREIGN KEY (user_id)          REFERENCES users (user_id)     ON DELETE RESTRICT,
  CONSTRAINT fk_bookings_flight FOREIGN KEY (flight_id)        REFERENCES flights (flight_id) ON DELETE RESTRICT,
  CONSTRAINT fk_bookings_return FOREIGN KEY (return_flight_id) REFERENCES flights (flight_id) ON DELETE RESTRICT,
  CONSTRAINT chk_bookings_passengers CHECK (passenger_count BETWEEN 1 AND 9),
  CONSTRAINT chk_bookings_price      CHECK (total_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ------------------------------------------------------------
-- passengers — one row per traveler on a booking
-- ("DOB in the past" is validated in PHP: MySQL rejects
--  non-deterministic functions like CURDATE() in CHECK constraints)
-- ------------------------------------------------------------
CREATE TABLE passengers (
  passenger_id INT NOT NULL AUTO_INCREMENT,
  booking_id   INT NOT NULL,
  full_name    VARCHAR(100) NOT NULL,
  dob          DATE NOT NULL,
  gender       ENUM('male','female','other') NOT NULL,
  passport_no  VARCHAR(20) DEFAULT NULL,
  PRIMARY KEY (passenger_id),
  KEY idx_passengers_booking (booking_id),
  CONSTRAINT fk_passengers_booking FOREIGN KEY (booking_id) REFERENCES bookings (booking_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ------------------------------------------------------------
-- tickets — one row per passenger per flight leg
-- fare is a snapshot of the flight's class price at booking time;
-- later price edits must not change past tickets.
-- On cancellation seat_number is set to NULL: the UNIQUE key still
-- holds (MySQL allows multiple NULLs), the seat is freed, and the
-- ticket row is kept for history.
-- ------------------------------------------------------------
CREATE TABLE tickets (
  ticket_id    INT NOT NULL AUTO_INCREMENT,
  booking_id   INT NOT NULL,
  passenger_id INT NOT NULL,
  flight_id    INT NOT NULL,
  seat_number  VARCHAR(4) DEFAULT NULL,
  class        ENUM('economy','business','first') NOT NULL,
  fare         DECIMAL(10,2) NOT NULL,
  status       ENUM('confirmed','checked_in','cancelled') NOT NULL DEFAULT 'confirmed',
  PRIMARY KEY (ticket_id),
  UNIQUE KEY uq_ticket_seat (flight_id, seat_number),
  KEY idx_tickets_booking (booking_id),
  KEY idx_tickets_passenger (passenger_id),
  CONSTRAINT fk_tickets_booking   FOREIGN KEY (booking_id)   REFERENCES bookings (booking_id)     ON DELETE CASCADE,
  CONSTRAINT fk_tickets_passenger FOREIGN KEY (passenger_id) REFERENCES passengers (passenger_id) ON DELETE CASCADE,
  CONSTRAINT fk_tickets_flight    FOREIGN KEY (flight_id)    REFERENCES flights (flight_id)       ON DELETE RESTRICT,
  CONSTRAINT chk_tickets_fare CHECK (fare >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ------------------------------------------------------------
-- payments — mock gateway records, one per booking attempt
-- ------------------------------------------------------------
CREATE TABLE payments (
  payment_id INT NOT NULL AUTO_INCREMENT,
  booking_id INT NOT NULL,
  amount     DECIMAL(10,2) NOT NULL,
  method     ENUM('card','upi','netbanking') NOT NULL,
  status     ENUM('pending','completed','refunded') NOT NULL DEFAULT 'pending',
  txn_ref    VARCHAR(40) DEFAULT NULL,
  paid_at    DATETIME DEFAULT NULL,
  PRIMARY KEY (payment_id),
  KEY idx_payments_booking (booking_id),
  CONSTRAINT fk_payments_booking FOREIGN KEY (booking_id) REFERENCES bookings (booking_id) ON DELETE CASCADE,
  CONSTRAINT chk_payments_amount CHECK (amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ------------------------------------------------------------
-- employees — airport staff roster (no direct flight link here;
-- flights are linked only via employee_flight_assignment)
-- ------------------------------------------------------------
CREATE TABLE employees (
  employee_id  INT NOT NULL AUTO_INCREMENT,
  full_name    VARCHAR(100) NOT NULL,
  role         ENUM('pilot','cabin_crew','ground','security') NOT NULL,
  contact_info VARCHAR(100) DEFAULT NULL,
  shift        ENUM('morning','evening','night') NOT NULL,
  PRIMARY KEY (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO employees (employee_id, full_name, role, contact_info, shift) VALUES
(1,  'Rajesh Khanna',   'pilot',      'rajesh.k@skyconnect.local',  'morning'),
(2,  'Priya Sharma',    'pilot',      'priya.s@skyconnect.local',   'evening'),
(3,  'David Chen',      'pilot',      'david.c@skyconnect.local',   'night'),
(4,  'Maria Gonzalez',  'pilot',      'maria.g@skyconnect.local',   'morning'),
(5,  'Anita Desai',     'cabin_crew', 'anita.d@skyconnect.local',   'morning'),
(6,  'James Wilson',    'cabin_crew', 'james.w@skyconnect.local',   'evening'),
(7,  'Fatima Al-Said',  'cabin_crew', 'fatima.a@skyconnect.local',  'night'),
(8,  'Kenji Tanaka',    'cabin_crew', 'kenji.t@skyconnect.local',   'evening'),
(9,  'Suresh Patel',    'ground',     'suresh.p@skyconnect.local',  'morning'),
(10, 'Linda Brown',     'ground',     'linda.b@skyconnect.local',   'night'),
(11, 'Omar Farouk',     'security',   'omar.f@skyconnect.local',    'evening'),
(12, 'Grace Okafor',    'security',   'grace.o@skyconnect.local',   'morning');

-- ------------------------------------------------------------
-- employee_flight_assignment — crew roster per flight (junction)
-- ------------------------------------------------------------
CREATE TABLE employee_flight_assignment (
  assignment_id INT NOT NULL AUTO_INCREMENT,
  employee_id   INT NOT NULL,
  flight_id     INT NOT NULL,
  assigned_at   TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (assignment_id),
  UNIQUE KEY uq_assignment_employee_flight (employee_id, flight_id),
  KEY idx_assignment_flight (flight_id),
  CONSTRAINT fk_assignment_employee FOREIGN KEY (employee_id) REFERENCES employees (employee_id) ON DELETE CASCADE,
  CONSTRAINT fk_assignment_flight   FOREIGN KEY (flight_id)   REFERENCES flights (flight_id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ------------------------------------------------------------
-- luggage — bags per ticket, added at staff check-in
-- ------------------------------------------------------------
CREATE TABLE luggage (
  luggage_id INT NOT NULL AUTO_INCREMENT,
  ticket_id  INT NOT NULL,
  weight     DECIMAL(5,2) NOT NULL,
  status     ENUM('checked_in','loaded','arrived','lost') NOT NULL DEFAULT 'checked_in',
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (luggage_id),
  KEY idx_luggage_ticket (ticket_id),
  CONSTRAINT fk_luggage_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (ticket_id) ON DELETE CASCADE,
  CONSTRAINT chk_luggage_weight CHECK (weight > 0 AND weight <= 32)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ------------------------------------------------------------
-- Triggers: the database owns the seat accounting.
-- Every ticket INSERT takes one seat (guarded — overselling raises
-- SQLSTATE 45000 and aborts the transaction); cancelling or deleting
-- a live ticket gives its seat back. Any client (the PHP app, the
-- SQL console, a future API) gets the same guarantee.
-- ------------------------------------------------------------
DELIMITER $$

CREATE TRIGGER trg_tickets_seat_decrement
BEFORE INSERT ON tickets
FOR EACH ROW
BEGIN
  UPDATE flights
     SET available_seats = available_seats - 1
   WHERE flight_id = NEW.flight_id
     AND available_seats >= 1;
  IF ROW_COUNT() = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Not enough seats available on this flight';
  END IF;
END$$

CREATE TRIGGER trg_tickets_seat_restore
AFTER UPDATE ON tickets
FOR EACH ROW
BEGIN
  IF OLD.status <> 'cancelled' AND NEW.status = 'cancelled' THEN
    UPDATE flights
       SET available_seats = available_seats + 1
     WHERE flight_id = NEW.flight_id;
  END IF;
END$$

-- Covers CASCADE deletes (e.g. a booking hard-deleted in the console)
-- so seats can never leak; the app itself never hard-deletes tickets.
CREATE TRIGGER trg_tickets_seat_restore_del
AFTER DELETE ON tickets
FOR EACH ROW
BEGIN
  IF OLD.status <> 'cancelled' THEN
    UPDATE flights
       SET available_seats = available_seats + 1
     WHERE flight_id = OLD.flight_id;
  END IF;
END$$

DELIMITER ;

-- ------------------------------------------------------------
-- sp_cancel_booking: the single cancel cascade, shared by the
-- user-facing and admin cancel paths (callers keep their own
-- authorization, departure checks and row locks, then CALL this
-- inside their transaction).
-- Cancelling tickets fires trg_tickets_seat_restore exactly once
-- per live ticket, so seats come back once per ticket per leg —
-- a double restore is impossible by construction.
-- ------------------------------------------------------------
DELIMITER $$

CREATE PROCEDURE sp_cancel_booking(IN p_booking_id INT)
BEGIN
  DECLARE v_status VARCHAR(10);

  SELECT status INTO v_status FROM bookings WHERE booking_id = p_booking_id;

  IF v_status IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Booking not found';
  END IF;
  IF v_status = 'cancelled' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Booking already cancelled';
  END IF;

  -- Cancel live tickets and free their seats (NULL keeps the
  -- UNIQUE(flight_id, seat_number) key happy for re-sale)
  UPDATE tickets
     SET status = 'cancelled', seat_number = NULL
   WHERE booking_id = p_booking_id AND status <> 'cancelled';

  -- Refund a completed payment; writes nothing for unpaid bookings
  UPDATE payments
     SET status = 'refunded'
   WHERE booking_id = p_booking_id AND status = 'completed';

  UPDATE bookings
     SET status = 'cancelled'
   WHERE booking_id = p_booking_id;
END$$

DELIMITER ;

-- ------------------------------------------------------------
-- Views for the admin reports page
-- ------------------------------------------------------------

-- Per-flight occupancy. seats_sold counts non-cancelled tickets;
-- occupancy is sold / (sold + still available) — i.e. how full the
-- sellable inventory is — with NULLIF guarding the divide-by-zero.
CREATE VIEW vw_flight_occupancy AS
SELECT f.flight_id,
       f.flight_number,
       CONCAT(f.departure_city, ' → ', f.arrival_city) AS route,
       f.departure_time,
       f.status,
       a.model AS aircraft_model,
       a.capacity,
       COUNT(t.ticket_id) AS seats_sold,
       f.available_seats,
       ROUND(100 * COUNT(t.ticket_id) / NULLIF(COUNT(t.ticket_id) + f.available_seats, 0), 1) AS occupancy_pct
FROM flights f
LEFT JOIN aircraft a ON f.aircraft_id = a.aircraft_id
LEFT JOIN tickets t ON t.flight_id = f.flight_id AND t.status <> 'cancelled'
GROUP BY f.flight_id, f.flight_number, f.departure_city, f.arrival_city,
         f.departure_time, f.status, a.model, a.capacity, f.available_seats;

-- Revenue per airline from fare snapshots: exact even after later
-- price edits, counting only live tickets of live bookings.
CREATE VIEW vw_revenue_by_airline AS
SELECT f.airline,
       COUNT(t.ticket_id) AS tickets_sold,
       COALESCE(SUM(t.fare), 0) AS revenue
FROM flights f
JOIN tickets t ON t.flight_id = f.flight_id AND t.status <> 'cancelled'
JOIN bookings b ON t.booking_id = b.booking_id AND b.status <> 'cancelled'
GROUP BY f.airline;
