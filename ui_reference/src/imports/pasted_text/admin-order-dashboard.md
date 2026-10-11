Redesign the Admin Order Management Dashboard for a print shop system with a clean, modern, and professional UI. Improve usability, clarity, and workflow efficiency.

🧩 Layout Structure
Keep a left sidebar navigation
Dashboard
Orders (active state highlighted with background + left border)
Shop Management
Sales Reports
Logout (bottom)
Top navbar:
Page title: Admin Panel
Notification bell with badge (e.g., 🔴 3)
User profile (name + role + avatar)
📊 Top Summary Section (NEW)

Add 4 dashboard cards above the table:

🟡 Pending Orders
🔵 Processing Orders
🟣 Ready Orders
🟢 Completed Orders

Each card should include:

Count number
Colored icon
Subtle shadow and rounded corners
🔍 Filters & Search (Improved UX)

Replace dropdown with filter tabs:

All | Pending | Processing | Ready | Completed
Active tab should be highlighted

Include:

Search bar (rounded, with icon)
Optional: Date filter
📄 Orders Table (Improved Design)

Columns:

Order ID
Customer Name
File Name
Print Details
Status
Actions
🧾 Print Details (Cleaner Format)

Instead of plain text, use badges/tags:

📄 A4 / Letter / Legal
⚫ B&W / 🎨 Color
🔢 Copies (e.g., x5)
🚦 Status System (Color-Coded)

Use consistent colors:

🟡 Pending
🔵 Processing
🟣 Ready
🟢 Completed

Display as rounded badges with icons.

⚡ Dynamic Action Buttons (IMPORTANT UX)

Buttons should change based on status:

Pending:
✅ Accept Order
❌ Reject
Processing:
🟣 Mark as Ready
Ready:
🟢 Mark as Completed
Completed:
👁 View Only

Use:

Primary button (solid)
Secondary actions inside 3-dot menu (⋯)
👁 Actions Column (Clean Layout)
Show:
👁 View icon
Main action button (based on status)
Optional dropdown for extra actions
🎨 Design Style
Minimalist and modern dashboard UI
Rounded corners (8–12px)
Soft shadows
Light background (gray/white)
Consistent spacing and alignment
Smooth hover effects on rows and buttons
✨ Micro-Interactions
Row hover highlight
Button hover animations
Smooth transitions
🧠 Optional (Advanced Features)
Add “Estimated Time” column (e.g., ⏱ 5 mins)
Tooltip on hover for detailed info
Pagination at bottom
🎯 Goal

Create a professional, easy-to-use admin interface that allows print shop owners to:

Quickly understand order status
Take immediate action
Manage workflow efficiently