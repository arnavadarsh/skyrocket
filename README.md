# Skyrocket — Airline Reservation and Management Portal

Skyrocket is a full-stack airline reservation and management system designed to streamline flight booking, scheduling, and administrative operations through an intuitive and responsive web interface.

Built using PHP, JavaScript, SQL, HTML, and CSS, the platform supports both customer-side booking workflows and internal airline management functionalities.

---

## Features

### Customer Features
- User registration and authentication
- Flight search and filtering
- Real-time seat selection and booking
- Booking confirmation and history tracking
- Responsive and user-friendly interface
- Profile management

### Admin Features
- Admin authentication dashboard
- Flight scheduling and management
- Monitor flight status:
  - Arrived
  - Delayed
  - Scheduled
  - Cancelled
- Passenger and booking management
- Airline operational control panel
- Secure backend database integration

---

## Tech Stack

### Frontend
- HTML5
- CSS3
- JavaScript

### Backend
- PHP

### Database
- MySQL

---

## System Architecture

The project follows a modular full-stack architecture where:
- PHP handles backend routing, authentication, and database operations
- MySQL stores flight, booking, and user information
- JavaScript powers dynamic UI interactions
- Frontend pages communicate seamlessly with backend services

The backend is tightly integrated with the frontend to provide a smooth and efficient booking experience.

---

## Project Structure

```bash
.
├── admin/                     # Admin dashboard and management modules
├── assets/                    # CSS, JS, images and static assets
├── includes/                  # Reusable backend components and DB configs
├── booking.php                # Flight booking page
├── booking_confirmation.php   # Booking confirmation workflow
├── booking_history.php        # User booking history
├── flights.php                # Flight listings and search
├── index.php                  # Landing page
├── login.php                  # User login
├── logout.php                 # Logout functionality
├── profile.php                # User profile management
├── register.php               # User registration
├── flight_booking.sql         # Database schema
└── README.md
