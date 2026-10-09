# Jhunlea Photography & Printing System
# Development Guidelines (AGENTS.md)


## 1. Project Overview

Jhunlea Photography & Printing System is a web-based management system designed for a photography and printing business.

The system will manage:

Printing requests
Photography bookings
Customer information
File submissions
Order tracking
Payment recording


## 2. Technology Stack

Backend:
Laravel

Frontend:
Blade Templates
Bootstrap 5
JavaScript

Database:
MySQL

Environment:
Windows
XAMPP
VS Code
GitHub


## 3. User Roles

The system must only have two roles:

1. Print Shop Owner

Responsibilities:
Manage printing orders
Manage photography bookings
Update request status
Manage services and packages
Record face-to-face payments


2. Customer

Responsibilities:
Submit printing requests
Upload files
Book photography services
Track request status
View order history


Do not create additional roles unless approved.


## 4. Laravel Development Rules

Follow Laravel MVC architecture.

Controllers:
Handle request processing
Validate input
Call appropriate models/services

Models:
Handle database relationships
Represent database entities

Views:
Handle presentation only
Avoid placing business logic inside Blade files


## 5. Database Rules

Always use Laravel migrations.

Never manually modify database tables without documentation.

Before changing database structure:

Review existing relationships
Create migration
Test migration


## 6. Git Rules

Branch structure:

main
|
dev
|
feature branches


Rules:

Never directly push to main
Create feature branches for major tasks
Write clear commit messages
Pull latest changes before starting work


## 7. UI Rules

The system should maintain the approved Jhunlea branding.

Design principles:

Clean
Professional
Responsive
User-friendly


Use:

Bootstrap components
Reusable Blade components
Consistent spacing


## 8. Security Rules

Always implement:

Validation
Authentication
Authorization
CSRF protection
Secure file upload handling


Never expose:

Database credentials
Private files
Sensitive user information


## 9. Development Process

Development must follow phases:

1. Planning
2. Architecture
3. Database Design
4. Authentication
5. Feature Development
6. Testing
7. Documentation


Do not skip planning before implementation.
