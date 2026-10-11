# JHUNLEA Photography & Printing

A React and Vite web app for Jhunlea Photography and Printing Station. Customers can request printing, book photography sessions, and track updates. The shop owner can review requests, manage packages and prices, schedule bookings, update statuses, and record in-person payments.

## Run locally

```sh
npm install
npm run dev
```

Open the local URL printed by Vite. Demo sign-in accounts:

- Owner: `shop@eprinting.com` / `shop123`
- Customer: `customer@eprinting.com` / `customer123`

Customers may also register an account. The Super Admin route and role have been removed.

## Features

- Customer registration and login
- Printing requests with file upload, specifications, and price estimates
- Photography bookings with package, theme, date, and optional print choices
- Request status, schedule, payment, and balance tracking
- Owner review tools for pricing, schedule confirmation, request status, and payments
- Face-to-face payment recording; the system does not process online payments

## Prototype data note

This Figma export has no server, API, or database schema. Authentication, requests, package settings, paper prices, and uploaded file previews currently use browser local storage for local demonstration. This is not secure multi-user storage. Before handling real customer data, replace it with server-side authentication, database persistence, and protected file storage.

## Repository

[JHUNLEA Photography and Printing Station System](https://github.com/francineanne/Jhunlea-Photography-and-Printing-Station-System)
