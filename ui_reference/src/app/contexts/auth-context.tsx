import { createContext, useContext, useEffect, useState, ReactNode } from "react";
import { useNavigate } from "react-router";

export type UserRole = "owner" | "customer";
export interface User {
  email: string;
  role: UserRole;
  name: string;
  phone?: string;
}

interface StoredUser extends User { password: string }
interface AuthContextType {
  user: User | null;
  initialized: boolean;
  login: (email: string, password: string) => boolean;
  logout: () => void;
  register: (email: string, password: string, name: string, phone?: string) => boolean;
}

const USERS_KEY = "jhunlea-users-v1";
const SESSION_KEY = "jhunlea-session-v1";
const AuthContext = createContext<AuthContextType | undefined>(undefined);
const demoUsers: StoredUser[] = [
  { email: "shop@eprinting.com", password: "shop123", role: "owner", name: "Jhunlea Shop Owner" },
  { email: "customer@eprinting.com", password: "customer123", role: "customer", name: "Juan Dela Cruz", phone: "09171234567" },
];

function readUsers(): StoredUser[] {
  try {
    const saved = localStorage.getItem(USERS_KEY);
    const users: Array<Omit<StoredUser, "role"> & { role: string }> = saved ? JSON.parse(saved) : [];
    const migrated: StoredUser[] = users.filter((entry) => entry.role === "admin" || entry.role === "owner" || entry.role === "customer")
      .map((entry) => ({ ...entry, role: entry.role === "admin" ? "owner" : entry.role } as StoredUser));
    return [...demoUsers, ...migrated.filter((user) => !demoUsers.some((demo) => demo.email === user.email))];
  } catch { return demoUsers; }
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [initialized, setInitialized] = useState(false);
  const navigate = useNavigate();

  useEffect(() => {
    try {
      const saved = localStorage.getItem(SESSION_KEY);
      if (saved) {
        const session: User = JSON.parse(saved);
        if (session.role === "owner" || session.role === "customer") setUser(session);
        else localStorage.removeItem(SESSION_KEY);
      }
    } catch { localStorage.removeItem(SESSION_KEY); }
    setInitialized(true);
  }, []);

  const login = (email: string, password: string) => {
    const found = readUsers().find((entry) => entry.email.toLowerCase() === email.trim().toLowerCase() && entry.password === password);
    if (!found) return false;
    const { password: _password, ...safeUser } = found;
    setUser(safeUser);
    localStorage.setItem(SESSION_KEY, JSON.stringify(safeUser));
    navigate(safeUser.role === "owner" ? "/admin" : "/customer");
    return true;
  };

  const logout = () => {
    setUser(null);
    localStorage.removeItem(SESSION_KEY);
    navigate("/login");
  };

  const register = (email: string, password: string, name: string, phone?: string) => {
    const users = readUsers();
    if (users.some((entry) => entry.email.toLowerCase() === email.trim().toLowerCase())) return false;
    const added: StoredUser = { email: email.trim(), password, name: name.trim(), phone, role: "customer" };
    const existing = JSON.parse(localStorage.getItem(USERS_KEY) || "[]") as StoredUser[];
    localStorage.setItem(USERS_KEY, JSON.stringify([...existing, added]));
    const { password: _password, ...safeUser } = added;
    setUser(safeUser);
    localStorage.setItem(SESSION_KEY, JSON.stringify(safeUser));
    navigate("/customer");
    return true;
  };

  return <AuthContext.Provider value={{ user, initialized, login, logout, register }}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (!context) throw new Error("useAuth must be used within an AuthProvider");
  return context;
}
