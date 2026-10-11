import { Outlet } from "react-router";
import { AuthProvider } from "../contexts/auth-context";

export function RootLayout() {
  return (
    <AuthProvider>
      <Outlet />
    </AuthProvider>
  );
}
