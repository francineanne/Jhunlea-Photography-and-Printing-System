Redesign the Admin Order Management Dashboard with a cleaner and more streamlined user experience. Focus on simplifying status updates and improving sidebar behavior.

🧩 Layout Improvements
Maintain a left sidebar + top navbar layout
Use a clean, modern dashboard style with proper spacing and alignment
Background: light gray or white
Cards and table: rounded corners with soft shadows
🍔 Sidebar (IMPORTANT FIX)

Redesign the sidebar into a single container component:

Sidebar must behave as one unified section
When collapsed (hamburger clicked):
Hide ALL content inside, including:
Logo
“Print Shop” name
Menu text labels
Show icons only
When expanded:
Show icons + labels + shop name
Add smooth transition animation when collapsing/expanding
Active menu item should have:
Highlight background
Left accent border

👉 Goal: No floating or visible text outside sidebar when collapsed

🔍 Search & Filter Section (SIMPLIFIED)
Keep only:
Search bar (full width or aligned left)
REMOVE:
Status filter tabs (Pending, Processing, Ready, Completed)

👉 Status filtering will be handled visually in the table instead

📄 Orders Table (Cleaner Design)

Columns:

Order ID
Customer Name
File Name
Print Details
Status
Actions
🧾 Print Details (Modern Format)

Display as badges/tags:

📄 Paper Size (A4, Letter, Legal)
🎨 Color (B&W / Color)
🔢 Copies (e.g., x5)
🚦 Status Display (Visual Only)

Use color-coded badges:

🟡 Pending
🔵 Processing
🟣 Ready
🟢 Completed

Keep it minimal and consistent.

⚡ Simplified Status Update Flow (KEY FEATURE)

Replace multiple buttons with ONE clear action per row:

If status = Pending
Show button: ✅ Mark as Processing
If status = Processing
Show button: 🟣 Mark as Ready
If status = Ready
Show button: 🟢 Mark as Completed
If status = Completed
No action button (or 👁 View only)

👉 Only ONE primary action button per row
👉 Remove “Accept” and “Update” buttons

👁 Actions Column (Minimalist)
Show:
👁 View icon (always visible)
One dynamic button (based on status)
Optional: 3-dot menu for extra actions
🎨 Design Style
Clean and minimal UI
Consistent spacing (8px or 12px grid)
Rounded corners (8–10px)
Soft shadows
Smooth hover effects
Modern button styles (filled primary, subtle secondary)
✨ Micro-Interactions
Smooth sidebar collapse animation
Row hover highlight
Button hover transitions
🎯 Goal

Create a simple, fast, and professional admin workflow where:

Status updates are one-click only
Interface is not cluttered
Sidebar behaves cleanly and consistently
User experience feels modern and efficient