JHUNLEA PHOTOGRAPHY & PRINTING SYSTEM
Project Overview, Functional Requirements, and Master Prompt for Claude
Project status: NEW SYSTEM — DEVELOPMENT STARTING FROM SCRATCH
Business: Jhunlea Photography & Printing
Primary users: Print Shop Owner and Customer
System type: Web-based photography booking, printing order management, and customer support system

PART 1 — PROJECT CONTEXT
1.1 Project Overview
Jhunlea Photography & Printing is a business that offers photography services, photoshoot packages, photo printing, document printing, photocopying, and other printing-related services.

The goal is to develop a new web-based system that helps the business manage customer requests, printing orders, photography bookings, service and package information, schedules, order progress, payment records, and customer communication in one organized platform.

The system is intended to make it easier for customers to explore services, submit requests, upload relevant files, request photoshoots, view request updates, and understand prices. It will also help the Print Shop Owner review and manage requests, maintain service and package information, confirm schedules, track production, and record face-to-face payments.
1.2 Important Project Status — Read Before Development
This is a brand-new project that is only beginning development. It is not an existing or completed system.

At the start of development, assume that the application, modules, database schema, routes, dashboards, customer accounts, orders, and business records have not yet been created.

Do not describe any part of the system as an existing module or existing implementation. Do not instruct yourself to preserve, rename, modify, or reuse legacy features or data that have not been built.

Build the system progressively from the requirements in this document. If source code or project files are created during development, inspect those actual files before modifying them. Do not invent files, database tables, routes, APIs, or features and claim that they already exist.

If an important implementation detail is not specified, recommend a sensible option and explain the assumption. Do not silently turn an assumption into a confirmed business requirement.
1.3 Main Problem to Address
Managing printing requests and photography appointments through disconnected or manual methods can make it difficult to organize customer details, uploaded files, service specifications, appointment schedules, pricing, and order progress. Customers may also need to contact the business repeatedly to ask about available packages, prices, or the status of their requests.

The proposed system will provide a central platform for submitting and managing these requests, communicating updates, presenting photography packages, and recording payments received in person.
1.4 General Objective
To design and develop a web-based Photography and Printing Management System for Jhunlea Photography & Printing that organizes printing orders and photography bookings, supports customer self-service, provides AI-assisted answers to relevant customer questions, and helps the owner manage requests, schedules, prices, and payment records.
1.5 Specific Objectives
1. Provide customers with a way to submit printing orders and upload the files needed for those orders.
2. Allow customers to browse photography services, themes, and packages, including package previews and clear descriptions.
3. Allow customers to submit photography booking requests with preferred dates, times, themes, and other relevant details.
4. Give the Print Shop Owner tools to review, accept, reject, price, schedule, and update customer requests.
5. Provide request reference numbers and status tracking for printing orders and photography bookings.
6. Maintain organized records of customers, services, packages, orders, bookings, and face-to-face payments.
7. Separate photography fees from optional photo-printing charges.
8. Provide an AI-assisted chatbot that answers questions about Jhunlea’s photography and printing services and recommends relevant packages based on customer needs.
9. Keep chatbot responses within the business’s photography and printing scope, with a safe fallback for unrelated questions.
10. Provide settings for supported interface preferences, including light mode and dark mode.
11. Protect customer information, uploaded files, account access, prices, and owner-only functions.
12. Make the system usable on desktop and mobile screens.
PART 2 — USERS, ACCESS, AND SYSTEM RULES
2.1 The System Has Exactly Two User Roles
A. Print Shop Owner
The Print Shop Owner is the primary business-side user. This role manages services, packages, themes, schedules, customer requests, printing orders, photography bookings, prices, payment records, and business-related system settings.
B. Customer
Customers can explore published services and packages, submit printing orders, request photography bookings, view their own requests and status updates, use the customer-support chatbot, and manage permitted profile and appearance settings.

Do not create a Super Admin, Staff, Manager, or other third role unless the project owner explicitly approves a future change. For the initial version, the system must use only Print Shop Owner and Customer.
2.2 Access Control
- Use a secure authentication system.
- Provide a login page and customer registration flow.
- Owner accounts must be created through a controlled owner-only setup process, not through public customer registration.
- Redirect each user to the appropriate interface based on their role.
- Customers must not be able to access owner pages, owner APIs, business configuration, or another customer's requests.
- Customers must not be able to change owner-controlled statuses, set final prices, confirm their own photography bookings, or record their own payments.
- Enforce permissions on the server/backend, not only by hiding buttons in the interface.
- Validate ownership of every request and uploaded file before displaying or downloading it.
- Include appropriate logout, session handling, validation, and error states.
2.3 Important Business Rules
1. Submitting a request does not automatically mean it is accepted.
2. Selecting a photography timeslot does not mean the appointment is confirmed.
3. Prices shown as estimates must be distinguished from owner-confirmed prices.
4. Payments are made face-to-face at the physical business location. The system records payments; it does not process online payments.
5. Photography booking status, printing production status, and payment status must be tracked separately.
6. A photography booking may be completed while its associated printed photographs are still being produced.
7. Customers may only view their own private request details and files.
8. AI recommendations are suggestions, not confirmed bookings, guaranteed availability, or binding quotations.
9. The chatbot must not invent service prices, package details, schedules, or business policies. It should use information configured in the system and state when the owner must confirm something.
10. The owner must be able to correct or update a request while preserving an appropriate status/history record.
PART 3 — CORE CUSTOMER EXPERIENCE
3.1 Customer Dashboard
Create a customer dashboard with clear entry points to:
- Browse Services
- Request Printing
- Photography Packages
- Book Photography
- My Printing Orders
- My Photography Bookings
- Request History
- Notifications
- AI Customer Assistant
- Profile and Settings
The dashboard may display counts and summaries calculated from actual database records, such as:
- Requests awaiting owner review
- Printing orders in progress
- Orders ready for pickup
- Upcoming confirmed photoshoots
- Completed requests
- Unpaid or partially paid balances
Do not display fake statistics or hard-coded counts as though they were real records. Provide helpful empty states for new customers who have not submitted any requests.
3.2 Customer Request Details and Tracking
Each printing order and photography booking must receive a unique reference number.

Display relevant details such as:
- Request/booking reference number
- Service type
- Submission date
- Requested date and time, when applicable
- Confirmed date and time, when applicable
- Selected theme or package, when applicable
- Printing specifications
- Uploaded files or safe file references
- Estimated price and confirmed price
- Payment status, amount paid, and remaining balance when applicable
- Current status
- Status history/timeline
- Owner's customer-visible remarks
- Any proposed schedule change or request for more information
Customers must not be able to edit protected fields or access another customer's records. Provide clear messages when the owner needs additional details or proposes a schedule change.
PART 4 — PRINTING ORDER MODULE
4.1 Supported Printing Services
The system should support configurable printing services such as:
- Document Printing
- Photocopying
- Photo Printing
- ID / Passport Picture Printing, if offered by the business
- Other printing services configured by the Print Shop Owner
The owner should be able to create, edit, publish, unpublish, and configure available services without requiring a developer to hard-code every service.
4.2 Customer Printing Request Form
Allow customers to:
1. Choose a printing service.
2. Upload the document or image to be printed.
3. Enter the number of copies.
4. Select paper size.
5. Select paper type when applicable.
6. Select color or black-and-white printing when applicable.
7. Select single-sided or double-sided printing when applicable.
8. Enter special instructions.
9. Review the request before submitting.
10. Submit the request to the Print Shop Owner.
11. Receive a reference number and submission date.
12. View the estimated or owner-confirmed price.
13. Track the order and payment status.
14. View previous and completed orders.
Show only specifications relevant to the selected service. For example, do not force document-specific paper settings on a service that does not use them.
4.3 File Upload and Security
- Validate required fields, file extensions, MIME types, and file size on both client and server.
- Use a configurable list of allowed formats appropriate for the business, such as PDF, JPG/JPEG, and PNG.
- Store uploads in a protected location rather than exposing private files through unrestricted public URLs.
- Use generated file names and prevent path traversal.
- Restrict file access to the customer who owns the request and authorized owner accounts.
- Provide clear upload errors and file-size guidance.
- Do not execute uploaded files.
- Include reasonable limits and safeguards against excessive uploads.
4.4 Printing Order Statuses
Use the following initial workflow unless a documented business rule requires an adjustment:
- Submitted
- Under Review
- Accepted
- Rejected
- Processing
- Ready for Pickup
- Completed
- Cancelled
A rejected request must have a reason visible to the customer. Record status changes with timestamps and the account that made the change. Customers cannot set owner-controlled statuses.

Suggested flow:

Customer submits request → Owner reviews request → Accepted or Rejected → Processing → Ready for Pickup → Payment/release according to business rules → Completed

The owner should be able to request clarification when an uploaded file is unreadable or the order specifications are incomplete.
PART 5 — PHOTOGRAPHY SERVICES, PACKAGES, AND BOOKINGS
5.1 Photography Booking & Packages
Create a dedicated customer-facing area for photography services and packages. It should not be limited to a static service card; customers must be able to inspect package details and proceed to a booking request.

The owner must be able to configure:
- Photography service name and description
- Package name
- Package price or “price requires confirmation” label
- Included services and deliverables
- Photoshoot duration, if applicable
- Number of people covered, if applicable
- Number or type of digital outputs, if applicable
- Optional printed outputs
- Theme and background options
- Package images or preview gallery
- Availability rules or booking notes
- Package status (published/unpublished)
- Terms, restrictions, and relevant customer instructions
Do not invent actual business prices, package contents, or guarantees. Use clearly labelled sample data during development and make it easy for the owner to replace it with approved business information.
5.2 Package Preview Experience
Each package should have a detail view that may include:
- Package name and short description
- Price or quotation notice
- Preview photographs or sample work
- Included services and outputs
- Photoshoot duration and applicable limits
- Optional add-ons
- Available theme choices
- Whether photo printing can be added
- Frequently asked questions or package notes
- A clear “Request This Package” or “Book This Package” action
Use optimized images and responsive galleries. Only display images that the business has permission to use. Do not use random images as if they were authentic Jhunlea customer work.
5.3 Customer Photography Booking Form
Customer information
- Customer name, populated from the logged-in account where possible
- Contact number
- Optional additional contact information, if needed
Photography details
- Photography service or photoshoot type
- Selected package, if applicable
- Number of people, when applicable
- Preferred date
- Preferred time or timeslot
- Preferred location or venue, if applicable
- Additional instructions
Theme and creative preferences
- Preferred theme or concept
- Preferred background or setup, if applicable
- Preferred color motif
- Outfit or styling notes, if applicable
- Optional reference/inspiration image upload
- Additional creative instructions
- An Other / Custom Theme option with a text field
The owner must be able to manage available themes and package options. Customers must not be forced to select a predefined theme when they want to describe a custom concept.
5.4 Optional Photo Printing
Ask customers:

“Do you also want to have your photos printed?”

Options:
- Yes, include photo printing in my request.
- No, digital photos only.
If Yes:
- Show relevant print sizes and available print options.
- Collect the number of prints and other applicable specifications.
- Calculate or estimate printing charges separately from the photography package.
- Show the combined estimate only when sufficient configured price information is available.
- Allow the owner to review and confirm the final amount.
If No:
- Hide or disable printing-specific fields.
- Do not add printing charges.
- Treat the request as photography-only.
Do not charge twice for the same printing service. If a booking includes prints, link the print details to the booking or create a related printing order with a clear relationship between the records.
5.5 Photography Schedule Rules
- A requested date/time is not a confirmed appointment.
- Check configured availability and conflicting confirmed bookings before the owner confirms a schedule.
- Allow the owner to accept, reject, or propose an alternative schedule.
- Store the originally requested schedule and the final agreed schedule.
- Display a clear distinction between “Requested” and “Confirmed.”
- Notify the customer when the booking status or agreed schedule changes.
- Prevent overlapping bookings where the business's configured rules say the same time slot cannot be used.
- Handle cancellation and rescheduling transparently.
5.6 Photography Booking Statuses
Use a photography-specific workflow, such as:
- Submitted
- Under Review
- Awaiting Customer Information
- Awaiting Schedule Confirmation
- Confirmed
- Reschedule Proposed
- Rejected
- Cancelled
- Photoshoot Completed
If photo printing is included, track print production separately. Do not force photography-only bookings through printing-specific statuses.
5.7 Owner Photography Management
The Print Shop Owner must be able to:
- View and filter incoming photography requests.
- Review customer details, selected package, theme, reference images, and instructions.
- Check the configured schedule and booking conflicts.
- Accept or reject a request with a reason where appropriate.
- Confirm the requested date/time or propose an alternative.
- Confirm package pricing or enter a quotation where required.
- Review optional printing specifications.
- View photography and printing fees separately.
- View the combined estimate or confirmed total.
- Update the booking and production statuses.
- View booking history.
- Record photoshoot completion.
- Record release/delivery of digital files or printed photographs where applicable.
- Record face-to-face payment after receiving it.
- Access any associated printing order without creating duplicate charges or inconsistent records.
PART 6 — OWNER MANAGEMENT AREA
Build a dedicated owner interface for managing the business. The initial owner-side navigation may include:
1. Overview
2. Printing Orders
3. Photography Bookings
4. Services
5. Photography Packages
6. Themes and Backgrounds
7. Schedule and Availability
8. Customers
9. Payments
10. Notifications / Customer Messages
11. Reports
12. Settings
The exact navigation and layout can be refined during design, but every included item must have a clear purpose and working functionality. Avoid adding menu items that lead to empty pages or nonfunctional buttons.
6.1 Owner Overview
Show useful operational information based on actual saved records, for example:
- New requests awaiting review
- Printing orders currently processing
- Orders ready for pickup
- Upcoming confirmed photoshoots
- Bookings awaiting schedule confirmation
- Unpaid or partially paid balances
- Recently updated requests
Use appropriate filters and links so the owner can open the relevant record from a summary.
6.2 Service, Package, and Theme Management
Allow the owner to add, edit, publish/unpublish, and manage:
- Printing services and their available specifications
- Photography services and packages
- Package preview images
- Themes, backgrounds, and sample concepts
- Optional photo-printing add-ons
- Prices, estimates, and quotation rules
- Customer-facing descriptions and instructions
Validate prices and required fields on the backend. Make clear which data is visible to customers.
6.3 Schedule Management
Provide a way for the owner to configure available days/times, review requested and confirmed appointments, and identify conflicts. Include a calendar or schedule view if feasible. A calendar display must be connected to actual booking records rather than static sample events.
6.4 Customer and Request Management
The owner should be able to find a customer or request, review relevant history, ask for clarification, and update permitted information. Use search, filters, and pagination where appropriate.
6.5 Reports
Provide useful, accurate reports if they fit the implementation scope, such as:
- Number of printing orders by period
- Number of photography bookings by period
- Popular services and packages
- Order status summary
- Face-to-face payments recorded by period
- Outstanding balances
Clearly distinguish recorded revenue/payment data from estimates and unpaid quotations. Do not describe an unpaid estimate as collected revenue.
PART 7 — FACE-TO-FACE PAYMENT RECORDING
7.1 Payment Policy
All customer payments are made face-to-face at Jhunlea Photography & Printing.

Do not implement online checkout, payment gateways, e-wallet integration, or automatic online payment confirmation. The system is for recording and monitoring payments, not processing payments online.
7.2 Customer Payment View
Customers may view:
- Estimated or confirmed total
- Amount paid
- Remaining balance
- Payment status
- A clear notice: “Payment is made face-to-face at Jhunlea Photography & Printing.”
7.3 Owner Payment Recording
The owner can record a payment only after receiving the actual payment in person. Store relevant details, such as:
- Related order or booking
- Amount received
- Date and time recorded
- Payment method, if the business wants to record it
- Owner account that recorded the payment
- Optional internal note or receipt reference
Support partial payments only if approved by the business rules. Keep an appropriate payment history and calculate the remaining balance reliably.

Payment states may include:
- Unpaid
- Partially Paid
- Paid
- Refunded, only if refund handling is explicitly implemented
Do not confuse payment state with request or production state. Never automatically mark a request as paid because it was accepted, processed, or completed. Customers must not be able to mark their own payment as received.
PART 8 — AI CUSTOMER ASSISTANT
8.1 Purpose
Include an AI-assisted chatbot to help customers when the owner is busy or unavailable. It should answer routine questions about Jhunlea’s photography and printing services and guide customers toward suitable services or packages.

The chatbot is a support assistant, not a replacement for the owner’s authority to approve bookings, confirm availability, set final prices, or record payments.
8.2 Allowed Topics
The chatbot should focus on:
- Printing services and specifications
- Photography services
- Photography packages and included features
- Package previews
- Themes and photoshoot concepts
- Optional photo printing
- General process for requesting a service
- How to submit a request
- How to understand request statuses
- Face-to-face payment policy
- Business information and FAQs configured by the owner
- General guidance on what information a customer should prepare
8.3 Off-topic Restriction
The chatbot must politely decline unrelated conversations, including requests unrelated to Jhunlea’s photography or printing business.

Suggested response:
“I’m here to help with Jhunlea’s photography and printing services. I can help you explore packages, printing options, booking requirements, and request updates. What service are you interested in?”

Do not allow the chatbot to be used as a general-purpose assistant. Implement this as an application-level topic restriction, not merely a prompt instruction. However, the system should still respond appropriately to greetings and basic conversational phrases needed to start a service-related conversation.
8.4 Package Recommendations
When a customer describes what they need, the chatbot should recommend relevant packages or services based on the information actually configured in the system.

Examples:
- A customer wants a family photoshoot → suggest packages that are configured as suitable for families.
- A customer needs a formal ID photo → direct them to the relevant configured ID/photo service.
- A customer wants digital photos and printed copies → explain the available packages and optional printing choices.
- A customer wants documents printed in color → guide them to the relevant printing request form.
Recommendations must:
- Be based on real configured services and package details.
- Explain briefly why each option matches the customer's stated need.
- Display known prices accurately, or state that the owner must confirm the price.
- Avoid inventing package inclusions, discounts, or availability.
- Provide a link or action to view the package or start a request, when available.
- Clearly state that a recommendation is not a confirmed booking.
8.5 Safe and Accurate AI Behavior
- Do not invent prices, package inclusions, opening hours, policies, or schedule availability.
- Use approved business information from the system as the source of truth.
- If the answer depends on current booking availability, consult the system's booking data or direct the customer to submit a request for owner confirmation.
- Do not expose other customers' records or private information.
- Do not allow the chatbot to confirm a booking, alter a price, change an owner-controlled status, or record a payment without an authorized workflow.
- If uncertain, say so and explain that the owner needs to confirm the detail.
- Avoid sending customer-uploaded private files or personal data to an external AI provider unless the integration, data handling, and user notice explicitly support it.
- Keep API keys on the server. Never expose secrets in frontend code.
8.6 Chatbot Interface
Include:
- A clear chatbot launcher in the customer interface
- Suggested prompts, such as “Recommend a photoshoot package,” “What printing options are available?” and “How do I book?”
- Clickable package/service recommendations
- A loading state and understandable error messages
- A way to start a fresh conversation
- A clear message when the question is outside the chatbot's scope
- A handoff path to contact the owner or submit a request when the chatbot cannot answer
Do not claim that the AI can respond while the website or server is offline. The chatbot's availability depends on the deployed application and the selected AI provider.
PART 9 — DISTINCTIVE FEATURE IDEAS
These are proposed enhancements, not assumptions about features that already exist. Prioritize the core workflow first, then implement the enhancements that fit the team's time, budget, and technical ability.
9.1 High-Value Features to Consider
A. Guided Print Specification Builder
Use a step-by-step form that changes based on the selected service. It can explain paper size, color mode, number of copies, and file requirements in plain language. This reduces incomplete or incompatible requests.
B. Live Request Timeline
Show a clear timeline of submission, owner review, acceptance, processing, ready-for-pickup, and completion. Only show events that have actually happened.
C. Photography Package Comparison
Let customers compare two or more packages side by side, including included services, duration, outputs, optional prints, and price/quotation status.
D. Smart Package Finder
Ask a few guided questions about the photoshoot purpose, number of people, desired outputs, theme, and approximate budget if the owner chooses to configure budget ranges. Recommend only packages that match the configured data.
E. Theme and Moodboard Builder
Allow customers to select a theme, colors, background style, and inspiration references before submitting a booking. Display a summary so the owner can understand the requested concept.
F. Booking Conflict Prevention
Use configured availability and confirmed bookings to prevent the owner from accidentally confirming overlapping appointments. Requested slots must remain unconfirmed until reviewed.
G. Printing File Preflight
Before submission, check basic properties such as supported file type, file size, and whether required files were attached. If technically feasible, show PDF page count or image dimensions. Do not promise print-quality verification unless a reliable check is implemented.
H. Combined Photography and Printing Request
Let customers request a photoshoot and optional printed outputs together while keeping photography and printing charges separately itemized.
I. Reorder from Previous Printing Request
Allow a customer to start a new request using the non-sensitive specifications of a previous order. The customer must confirm the files and details before resubmitting; do not silently duplicate or automatically process an old order.
J. Owner-Managed FAQ and AI Knowledge
Let the owner maintain approved answers about services, packages, preparation, payment policy, and common questions. The chatbot should use these approved answers and configured service data rather than relying on unsupported guesses.
K. Customer Notification Center
Provide in-system notifications when an order is accepted, a quotation is updated, a schedule is proposed, a booking is confirmed, or an order is ready for pickup. Email notifications can be considered as a separate optional integration.
L. Dark Mode and Light Mode
Provide a setting that switches between light and dark appearance. Store the user's preference where appropriate, apply it consistently across pages, and maintain readable contrast. This is an interface preference, not a separate user role.
9.2 Optional Later Enhancements
- QR-coded request reference for faster owner lookup or pickup verification. QR codes must not expose private customer information.
- Printable order/job sheet for the shop's production workflow.
- Customer approval of a quotation before the owner begins work, when applicable.
- Internal production checklist for print jobs.
- Booking reminders, if an appropriate notification channel is configured.
- Downloadable receipt/acknowledgment for recorded in-person payments, clearly distinguishing it from an online payment receipt.
- Basic analytics showing popular services and common customer requests.
- Accessible interface improvements, including keyboard navigation and clear form errors.
Do not implement all optional ideas automatically. First ensure that authentication, data integrity, order/booking workflows, payment recording, and security work correctly.
PART 10 — DESIGN AND USER EXPERIENCE
10.1 Brand Identity
Use the business name:

JHUNLEA
PHOTOGRAPHY & PRINTING

Create a clean, professional wordmark suitable for a photography and printing business. A simple camera and/or printer-inspired mark may be used if it fits the design. The logo must remain readable in the login page, sidebar, header, and smaller layouts.
10.2 Color Palette
Primary colors:
- Black: #171717
- White: #FFFFFF
- Red: #C62828
Supporting light gray and dark gray may be used for borders, secondary text, subtle backgrounds, and disabled controls.

Suggested use:
- Red for primary actions, active navigation indicators, and important highlights.
- Black for headings and strong text.
- White for main surfaces and cards.
- Neutral gray for borders, dividers, and secondary information.
Maintain sufficient contrast and readability.
10.3 Visual Style
A restrained glassmorphism effect may be used for selected surfaces, such as overlays or a login panel, if it does not reduce readability or usability. Do not apply blur and transparency to every component.

The overall UI should be professional, clean, responsive, and appropriate for a small photography and printing business. Use consistent spacing, form controls, tables, cards, and navigation. Avoid unnecessary visual clutter and excessive animations.

Provide light and dark themes. Make sure both themes support all pages, forms, dialogs, tables, dropdowns, and chatbot components.
10.4 Design Scope
Because this is a new system, create the design and layouts based on these requirements. Do not preserve a nonexistent legacy layout. Establish reusable components and shared theme variables so the UI remains consistent as the system grows.

All visible buttons, navigation items, forms, and actions must work. Do not leave fake links, placeholder controls that appear functional, or buttons without behavior.
PART 11 — DATABASE AND TECHNICAL DESIGN
11.1 Design the Database from the Requirements
Design a new database schema appropriate for the workflows. Potential entities may include:
- Users
- Customer Profiles or Contact Details, if needed
- Printing Services
- Photography Services
- Photography Packages
- Photography Themes
- Package Images or Media
- Printing Orders
- Printing Order Items or Specifications
- Photography Bookings
- Booking Schedule/Availability
- Optional Printing Items linked to Photography Bookings
- Payment Records
- Request Status History
- Notifications
- Chat Sessions and Messages, if conversation history is stored
- Owner-managed FAQs or AI knowledge entries
- Application/appearance settings, where appropriate
These are candidate entities, not a command to create unnecessary tables. Explain relationships, key fields, foreign keys, validation rules, and how duplicate charges or orphaned records will be prevented before implementation.

Use appropriate constraints, indexes, timestamps, and database transactions for critical operations. Keep printing and photography data related where useful, but do not collapse different workflows into one ambiguous status field.
11.2 Data Integrity
- Store money using an appropriate exact numeric representation, not floating-point arithmetic.
- Calculate totals and balances on the backend.
- Validate all client-supplied prices, statuses, and identifiers on the server.
- Use transactions for multi-step operations that must succeed or fail together.
- Keep a status history for important customer requests.
- Ensure payment records are associated with the correct order or booking.
- Avoid duplicate payments, duplicate printing charges, and accidental duplicate booking records.
- Use database migrations or another documented schema setup process so the development environment can be recreated reliably.
- Provide seed data only for clearly labelled development/demo purposes; do not present sample records as real business transactions.
11.3 Security Requirements
- Hash passwords using the framework's established secure password-hashing functionality.
- Use parameterized database access or a safe ORM.
- Protect against SQL injection, cross-site scripting, cross-site request forgery where applicable, broken access control, and insecure direct object references.
- Validate and authorize every backend action.
- Protect uploaded files.
- Keep API keys and credentials in server-side environment variables.
- Do not commit secrets into source control.
- Use safe error messages that do not expose stack traces or sensitive data to customers.
- Apply sensible request limits to uploads and AI endpoints.
- Provide a secure initial owner-account setup procedure.
- Avoid logging passwords, API keys, or unnecessary personal information.
11.4 Technology Choices
Before implementation, inspect the actual development environment only if the user has provided a real project directory or files. Otherwise, recommend a suitable stack and explain why it fits the team's skills and deployment needs.

Do not assume a specific framework, database, hosting provider, or API has already been configured. State the recommended stack clearly and list the software, environment variables, accounts, and setup steps required.

Prefer a maintainable architecture with clear separation between frontend presentation, backend business rules, database access, file handling, and external AI/API integrations. Do not introduce unnecessary complexity just to use more technologies.
PART 12 — FREE OR LOW-COST API OPTIONS TO EVALUATE
These are options to evaluate during planning, not mandatory dependencies. Pricing, quotas, commercial-use terms, and free-tier conditions can change, so verify the official documentation before choosing a provider. The core system should still work if an optional third-party API is unavailable.
12.1 AI Chatbot
Possible providers to evaluate:
- Google Gemini API — may offer a free usage tier subject to current model, quota, region, and terms.
- Groq API — may offer developer access with rate limits and model availability restrictions.
- Hugging Face Inference Providers — availability, credits, and limits depend on the current offering.
Choose one provider only after comparing the current terms, supported models, privacy requirements, rate limits, and expected usage. Keep the API key on the backend. Do not assume a free tier permits every commercial use or unlimited traffic.

The chatbot should use server-controlled prompts, topic restrictions, and approved service/package data. For reliable package recommendations, retrieve actual configured packages from the database instead of asking the model to invent a package list.
12.2 Email Notifications (Optional)
Possible option:
- Resend or another transactional email provider with a current developer/free allowance.
Verify the current free-tier limits and domain-verification requirements. Email is optional; the initial system can use in-app notifications if email setup is outside the project scope.
12.3 Maps or Location (Optional)
If customers need to provide or view a venue, evaluate a map/geocoding provider such as OpenStreetMap-based services. Review each provider's usage policy and rate limits. Do not rely on a public geocoding endpoint for unrestricted high-volume production use.

A map integration is not necessary if a simple venue/address text field meets the business need.
12.4 QR Code (Optional)
A QR code can be generated locally by a suitable library; an external API may not be needed. Encode only a random reference or non-sensitive request identifier, not a customer's contact information, private file URL, or payment details. Verify access on the server after scanning.
12.5 Image Processing (Optional)
Use a maintained server-side image library to create thumbnails or optimize package preview images if supported by the chosen stack. Do not upload private customer files to third-party image services unless there is a clear requirement and appropriate disclosure.
12.6 API Selection Rules
- Verify the official documentation and current terms before implementation.
- Prefer APIs with clear quotas, stable documentation, and acceptable privacy terms.
- Add graceful error handling and rate limits.
- Never expose secret keys in browser JavaScript.
- Avoid making the entire application dependent on a free third-party service.
- Document which features require an API key and how to run the application when the key is absent.
- Do not send customer printing documents or private photos to an AI provider unless necessary, disclosed, and explicitly supported by the design.
PART 13 — IMPLEMENTATION PLAN
Note (October 30, 2026): the phases below are the original outline. The working phase plan and numbering are in docs/DEVELOPMENT_PLAN.md (Phase 8 is walk-in logging and income reports; Phase 9 is polish and release).
Develop the project in stages. Do not try to build every module in one uncontrolled code-generation step.
Phase 1: Requirements and Architecture
- Confirm the initial feature scope and business rules.
- Recommend the technology stack.
- Define the application architecture.
- Draft the database ERD/schema.
- Define user roles, routes, and access permissions.
- Define the printing and photography status workflows.
- Identify external APIs and setup requirements.
Deliverable: A reviewed implementation plan before major coding begins.
Phase 2: Project Setup and Foundation
- Create the new project structure.
- Configure the development environment.
- Set up database connection and migrations/schema creation.
- Create shared layouts, navigation, branding, and theme tokens.
- Implement authentication, secure owner setup, customer registration, and role-based access.
Deliverable: A running application with secure login and role-specific access.
Phase 3: Services and Packages
- Implement printing service configuration.
- Implement photography service, package, and theme management.
- Add package preview pages and customer browsing.
- Add light/dark mode.
Deliverable: The owner can configure services and customers can browse accurate published information.
Phase 4: Printing Requests
- Build printing request forms and secure uploads.
- Implement server-side validation and price estimates where configured.
- Implement owner review and order status updates.
- Implement customer order tracking.
Deliverable: A complete printing-request workflow.
Phase 5: Photography Bookings
- Build package selection and booking forms.
- Implement theme and creative-preference fields.
- Implement availability checks and schedule confirmation.
- Implement optional photo printing with separate price components.
- Implement owner booking management and customer tracking.
Deliverable: A complete photography booking workflow without duplicate charges.
Phase 6: Payment Records and Notifications
- Implement face-to-face payment recording.
- Calculate paid amounts and outstanding balances.
- Keep payment status separate from order/booking status.
- Implement in-app notifications and request history.
Deliverable: Accurate payment records and customer updates.
Phase 7: AI Customer Assistant
- Connect the selected AI provider on the backend.
- Restrict the chatbot to approved business topics.
- Provide package recommendations based on database data.
- Add fallback responses, rate limits, error handling, and owner handoff.
- Test off-topic prompts and attempts to obtain private information.
Deliverable: A bounded customer assistant that uses actual business data and does not claim authority it does not have.
Phase 8: Testing, Security, and Refinement
- Test customer and owner workflows.
- Test role and record-level access.
- Test uploads and invalid inputs.
- Test booking conflicts, cancellation, and rescheduling.
- Test pricing, partial payments if supported, and balances.
- Test light/dark themes and responsive layouts.
- Test chatbot recommendations, off-topic refusal, and provider failure.
- Fix errors and document remaining limitations.
Deliverable: A stable, demonstrable system with a clear test report.
Phase 9: Deployment and Documentation
- Document deployment requirements and environment variables.
- Configure production database and secure credentials.
- Use HTTPS in production.
- Explain backup and recovery considerations.
- Document owner setup and the process for updating services, packages, and themes.
- Prepare user instructions and project presentation materials.
Deliverable: A deployable system and documentation suitable for project demonstration.
PART 13A — ADDITIONAL REQUIREMENT (October 30, 2026): WALK-IN LOGGING AND INCOME REPORTS
Added at the request of the project adviser. The shop currently logs walk-in printing, in-person bookings and payments by hand and has no single view of its total income. The system must be that log.
- The owner can create printing orders and photography bookings for a walk-in customer (a customer record with a name and a phone number and no login). The same schedule conflict checks apply, so a walk-in booking blocks the time for online customers. Every order and booking records its source (online or walk-in).
- The Payments module contains the counter: record the full payment of an existing order or booking (cash or GCash, face to face, with cash tendered and change due as a counter aid), and a quick walk-in flow that creates the order and records the payment in one transaction. A printable payment slip (an acknowledgment of a recorded payment, not an official BIR receipt) is available.
- The payments logbook lists every recorded payment (online and walk-in) and can be filtered and printed.
- The reports show, for any chosen day or period (default today, with previous and next day, presets and all time): the income (the sum of recorded, non-voided payments; never estimates and never awaiting amounts), the number of transactions, cash vs GCash, online vs walk-in, printing vs photography, per service and package, and the outstanding balance; the list of that period's transactions with their details; charts; and a CSV and print export. Voided payments stay visible with their reason but are not counted.
- The shop rules do not change: no partial payment or downpayment, full payment face to face, release only when fully paid, and the system only records payments.
PART 14 — TESTING AND ACCEPTANCE CRITERIA
The system is not considered complete until the relevant requirements have been implemented and tested.
Authentication and Roles
1. The system has exactly two active roles: Print Shop Owner and Customer.
2. Public customer registration cannot create an owner account.
3. Customers cannot access owner-only routes or APIs.
4. Customers cannot access another customer's records or files.
5. The owner can access the management functions permitted by the role.
Printing
6. A customer can submit a printing request with valid specifications and allowed files.
7. Invalid file types, oversized uploads, missing required fields, and invalid quantities are rejected safely.
8. The owner can review, accept, reject, and update printing orders.
9. Rejected requests include an appropriate reason.
10. Customers can see the latest printing status and relevant price information.
11. Customers cannot assign owner-controlled statuses to their own orders.
Photography
12. Customers can browse photography services and package details.
13. Package previews, inclusions, and prices/quotation notices reflect configured data.
14. A customer can submit a booking with schedule and theme preferences.
15. A selected timeslot is not automatically confirmed.
16. Conflicting bookings are detected according to configured availability rules.
17. The owner can confirm, reject, or propose an alternative schedule.
18. Photography-only requests receive no accidental printing charge.
19. Bookings that include prints show separate photography and printing components.
20. Photoshoot completion and print production can be tracked separately.
Payments
21. The system does not process online payments.
22. Only the owner can record a payment.
23. Payment records reflect money actually received face-to-face.
24. Amount paid, remaining balance, and payment state are calculated correctly.
25. Payment state remains separate from booking/order state.
AI Assistant
26. The chatbot answers within the approved photography and printing scope.
27. It politely redirects unrelated questions.
28. Package recommendations use actual configured package data.
29. It does not invent prices, package inclusions, or availability.
30. It cannot authorize bookings, alter owner-controlled data, or record payments on its own.
31. API failure or missing credentials produce a clear fallback instead of breaking the application.
32. Private customer records and files are not exposed to other customers through the chatbot.
UI, Data, and Reliability
33. Branding uses “JHUNLEA — PHOTOGRAPHY & PRINTING.”
34. The primary palette uses black #171717, white #FFFFFF, and red #C62828, with supporting neutrals.
35. Light mode and dark mode are usable and readable.
36. The layout works on desktop and mobile.
37. Every visible action works and no important route leads to a dead end.
38. Data persists after refresh and after signing out and back in.
39. Status history, payment history, and request relationships remain consistent.
40. The available automated tests pass, and important manual tests are documented.
41. The final report identifies implemented features, changed files, database setup/migrations, tests performed, and known limitations.
PART 15 — MASTER PROMPT FOR CLAUDE
Copy the prompt below into Claude when you are ready to begin working on the project.
ROLE

Act as a senior full-stack developer, software architect, database designer, UI/UX designer, and security-conscious technical mentor. Help build the Jhunlea Photography & Printing System in clear, testable stages.

PROJECT STATUS — CRITICAL

This is a new system being started from scratch. There is no pre-existing application or legacy system to maintain. Do not assume there are existing modules, routes, tables, customer accounts, owner dashboards, uploaded files, business records, or working features. Do not use instructions such as “preserve the existing module,” “rename the existing page,” or “reuse existing code” unless actual project files created during this development later make that instruction relevant.

Build only from the requirements and real files available. Never claim that a file, table, route, feature, or implementation exists unless you have actually inspected it.

PROJECT GOAL

Create a web-based Photography and Printing Management System for Jhunlea Photography & Printing. It must support printing requests, photography packages and bookings, optional photo printing, schedule confirmation, customer request tracking, face-to-face payment recording, owner management, and a restricted AI customer assistant.

ROLES

Use exactly two roles:
1. Print Shop Owner
2. Customer
Implement role-based authorization on the backend. Public customer registration must not create an owner account.

CORE WORKFLOWS

Printing:
Customer submits request and file → Owner reviews → Accepted or Rejected → Processing → Ready for Pickup → Payment/release according to business policy → Completed.

Photography:
Customer browses package → Submits booking request and preferences → Owner reviews → Schedule and price are confirmed or an alternative is proposed → Photoshoot takes place → Photoshoot is marked complete. If printing is included, its production and release must be tracked separately.

Payment:
Payment is made face-to-face at the physical shop. The owner records payment only after receiving it. Do not build online payment processing.

AI assistant:
Answer questions about configured printing and photography services, recommend relevant packages using real system data, and politely redirect unrelated questions. Never invent prices, package details, or availability. The AI cannot independently confirm bookings, alter owner-controlled records, or record payments.

DESIGN

Use the business name “JHUNLEA — PHOTOGRAPHY & PRINTING.” Use black #171717, white #FFFFFF, and red #C62828, with neutral grays as supporting colors. Create a professional, responsive interface. Glassmorphism may be used sparingly where readable. Provide functional light/dark mode. Because this is a new system, establish the layouts and reusable components from these requirements rather than preserving a nonexistent design.

DEVELOPMENT METHOD

Work in small, reviewable stages. Begin by:
1. Summarizing the requirements and identifying ambiguities.
2. Recommending a suitable technology stack based on the actual development environment and the team's needs.
3. Proposing the architecture, database schema/ERD, route plan, and access-control rules.
4. Defining printing and photography workflows and their separate status models.
5. Listing required software, environment variables, optional APIs, and setup steps.
6. Presenting the implementation plan before generating a large amount of code.
After the plan is reviewed, implement one phase at a time. For each phase:
- Explain which files will be created and why.
- Provide complete, consistent code for the files being implemented.
- Include database migrations or setup instructions.
- Explain how to run and test the feature.
- Test relevant success cases, validation errors, permissions, and edge cases.
- Fix errors before moving to the next phase.
- Keep a concise record of completed work, pending work, and decisions.
Do not output only mockups or static interfaces when the requirement calls for working functionality. Connect forms, actions, database operations, authorization, validation, and status updates. Do not create fake counts, fake booking availability, or pretend API responses.

DATABASE AND SECURITY

Design the database based on the workflows. Use sound relationships, foreign keys, indexes, backend validation, secure password hashing, safe database queries, protected uploads, and server-side authorization. Keep payment status separate from request status. Avoid duplicate print charges when printing is linked to a photography booking. Store prices using an appropriate exact numeric type and calculate totals on the backend. Keep API keys in environment variables and never expose them in frontend code.

API POLICY

Treat third-party APIs as optional until selected and verified. Confirm current documentation, free-tier limits, privacy terms, and commercial-use rules before integration. The application must handle missing API keys or provider outages gracefully. Do not send private customer documents or photographs to external services unless the need, disclosure, and data handling are approved.

SCOPE CONTROL

Prioritize a working, secure core system over unnecessary extras. Recommended enhancements include a package comparison view, package finder, theme/moodboard builder, request timeline, printing-file preflight, in-app notifications, reorder flow, QR reference lookup, and owner-managed chatbot FAQs. Present these as options and agree on priorities before implementing them.

FINAL EXPECTATION

The result should be a coherent, maintainable, functional new system, not a patch to a supposed old application. At each milestone, report what is genuinely implemented, what was tested, what remains incomplete, and any assumptions that still need confirmation. Never claim success for functionality that has not been implemented and tested.
PART 16 — DECISIONS TO CONFIRM BEFORE FULL IMPLEMENTATION
These points should be clarified with the project team/business owner rather than silently assumed:
1. Which technology stack will be used, based on the team's skills and actual environment?
2. What are the real printing services, paper options, accepted file types, and maximum upload sizes?
3. What photography packages, prices, included outputs, and add-ons does Jhunlea actually offer?
4. What are the business's opening hours, available photoshoot days, appointment durations, and cancellation/rescheduling rules?
5. Will customers be required to create an account before submitting any request?
6. Which contact details are required from customers?
7. Will the owner allow partial payments, and what is the policy for releasing outputs when a balance remains?
8. Which AI provider, if any, will be used, and what budget/privacy restrictions apply?
9. Are email notifications needed, or are in-system notifications sufficient for the first version?
10. Which optional enhancements are necessary for the first demonstration, and which can wait until a later phase?
If these answers are not yet available, document reasonable temporary assumptions, label sample information clearly, and make the relevant settings configurable.
End of project specification.