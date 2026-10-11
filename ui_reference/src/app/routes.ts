import { createBrowserRouter, Navigate, Outlet } from "react-router";
import { createElement } from "react";
import { LandingPage } from "./pages/landing-page";
import { LoginPage } from "./pages/login-page";
import { RegisterPage } from "./pages/register-page";
import { AdminDashboard } from "./pages/admin-dashboard";
import { CustomerDashboard } from "./pages/customer-dashboard";
import { useAuth } from "./contexts/auth-context";
import { RootLayout } from "./components/root-layout";

function RequireRole({ role }: { role: "owner" | "customer" }) {
  const { user, initialized } = useAuth();
  if (!initialized) return createElement("div", { className: "min-h-screen bg-white" });
  if (!user) return createElement(Navigate, { to: "/login", replace: true });
  if (user.role !== role) return createElement(Navigate, { to: user.role === "owner" ? "/admin" : "/customer", replace: true });
  return createElement(Outlet);
}

function OwnerGate() { return createElement(RequireRole, { role: "owner" }); }
function CustomerGate() { return createElement(RequireRole, { role: "customer" }); }

export const router = createBrowserRouter([
  {
    path: "/",
    Component: RootLayout,
    children: [
      {
        index: true,
        Component: LandingPage,
      },
      {
        path: "login",
        Component: LoginPage,
      },
      {
        path: "register",
        Component: RegisterPage,
      },
      {
        Component: OwnerGate,
        children: [{ path: "admin", Component: AdminDashboard }],
      },
      {
        Component: CustomerGate,
        children: [{ path: "customer", Component: CustomerDashboard }],
      },
    ],
  },
]);
