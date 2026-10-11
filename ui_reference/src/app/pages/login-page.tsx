import { Link } from "react-router";
import { Printer, Mail, Lock, Info, ChevronDown, ChevronUp } from "lucide-react";
import { useState } from "react";
import logo from "../../assets/jhunlea-printing-services-badge.png";
import { useAuth } from "../contexts/auth-context";

export function LoginPage() {
  const [showDemoAccounts, setShowDemoAccounts] = useState(false);
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const { login } = useAuth();

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    
    const success = login(email, password);
    if (!success) {
      setError("Invalid email or password");
    }
  };

  return (
    <div className="min-h-screen flex">
      {/* Left Side - Branding */}
      <div className="hidden lg:flex lg:w-1/2 bg-gradient-to-br from-primary to-secondary p-12 items-center justify-center relative overflow-hidden">
        {/* Decorative background */}
        <div className="absolute inset-0 opacity-10">
          <div className="absolute top-20 left-20 w-64 h-64 bg-white rounded-full blur-3xl"></div>
          <div className="absolute bottom-20 right-20 w-96 h-96 bg-accent rounded-full blur-3xl"></div>
        </div>

        <div className="relative z-10 text-center text-white">
          <img src={logo} alt="Jhunlea Photography and Printing" className="w-80 h-auto mx-auto mb-8" />
          <h1 className="text-5xl mb-6">Welcome Back!</h1>
          <p className="text-xl text-white/90 max-w-md mx-auto">
            Access your dashboard to manage orders, track performance, and grow your printing
            business.
          </p>
          <div className="mt-12 flex items-center justify-center gap-4">
            <Printer className="w-12 h-12 text-accent" />
            <div className="h-12 w-px bg-white/30"></div>
            <p className="text-left">
              <span className="block text-accent">Trusted by</span>
              <span className="block text-2xl">50+ Print Shops</span>
            </p>
          </div>
        </div>
      </div>

      {/* Right Side - Login Form */}
      <div className="w-full lg:w-1/2 flex items-center justify-center p-8 bg-white">
        <div className="w-full max-w-md">
          {/* Mobile Logo */}
          <div className="lg:hidden mb-8 text-center">
            <img src={logo} alt="Jhunlea Photography and Printing" className="w-48 h-auto mx-auto" />
          </div>

          <div className="mb-8">
            <h2 className="text-4xl text-primary mb-2">Sign In</h2>
            <p className="text-muted-foreground">Enter your credentials to access your account</p>
          </div>

          <form className="space-y-6" onSubmit={handleSubmit}>
            {/* Error Message */}
            {error && (
              <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
                {error}
              </div>
            )}
            
            {/* Email Input */}
            <div>
              <label htmlFor="email" className="block mb-2 text-foreground">
                Email Address
              </label>
              <div className="relative">
                <div className="absolute left-4 top-1/2 -translate-y-1/2 text-muted-foreground">
                  <Mail className="w-5 h-5" />
                </div>
                <input
                  type="email"
                  id="email"
                  placeholder="you@example.com"
                  className="w-full pl-12 pr-4 py-3 bg-input-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-primary transition-all"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                />
              </div>
            </div>

            {/* Password Input */}
            <div>
              <label htmlFor="password" className="block mb-2 text-foreground">
                Password
              </label>
              <div className="relative">
                <div className="absolute left-4 top-1/2 -translate-y-1/2 text-muted-foreground">
                  <Lock className="w-5 h-5" />
                </div>
                <input
                  type="password"
                  id="password"
                  placeholder="••••••••"
                  className="w-full pl-12 pr-4 py-3 bg-input-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-primary transition-all"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                />
              </div>
            </div>

            {/* Remember Me & Forgot Password */}
            <div className="flex items-center justify-between">
              <label className="flex items-center gap-2 cursor-pointer">
                <input
                  type="checkbox"
                  className="w-4 h-4 rounded border-border text-primary focus:ring-2 focus:ring-primary"
                />
                <span className="text-sm text-muted-foreground">Remember me</span>
              </label>
              <a href="#" className="text-sm text-primary hover:underline">
                Forgot password?
              </a>
            </div>

            {/* Login Button */}
            <button
              type="submit"
              className="w-full bg-primary text-primary-foreground py-3 rounded-lg hover:bg-primary/90 transition-all shadow-lg hover:shadow-xl"
            >
              Sign In
            </button>

            {/* Divider */}
            <div className="relative my-6">
              <div className="absolute inset-0 flex items-center">
                <div className="w-full border-t border-border"></div>
              </div>
              <div className="relative flex justify-center text-sm">
                <span className="px-4 bg-white text-muted-foreground">or</span>
              </div>
            </div>

            {/* Register Link */}
            <div className="text-center space-y-3">
              <Link
                to="/register"
                className="block w-full bg-accent text-accent-foreground py-3 rounded-lg hover:bg-accent/90 transition-all shadow-md hover:shadow-lg"
              >
                Create New Account
              </Link>
              <Link to="/" className="block text-sm text-primary hover:underline">
                ← Back to Home
              </Link>
            </div>

            {/* Demo Accounts - Collapsible at bottom */}
            <div className="mt-6 pt-6 border-t border-border">
              <button
                type="button"
                onClick={() => setShowDemoAccounts(!showDemoAccounts)}
                className="w-full flex items-center justify-between text-sm text-muted-foreground hover:text-foreground transition-colors"
              >
                <span className="flex items-center gap-2">
                  <Info className="w-4 h-4" />
                  Demo Account Credentials
                </span>
                {showDemoAccounts ? (
                  <ChevronUp className="w-4 h-4" />
                ) : (
                  <ChevronDown className="w-4 h-4" />
                )}
              </button>

              {showDemoAccounts && (
                <div className="mt-3 bg-secondary/10 border border-secondary/30 rounded-lg p-4">
                  <div className="space-y-2 text-xs">
                    <div className="bg-white/50 rounded p-2">
                      <p className="text-foreground"><strong>Print Shop Owner:</strong></p>
                      <p className="text-muted-foreground">Email: shop@eprinting.com</p>
                      <p className="text-muted-foreground">Password: shop123</p>
                    </div>
                    <div className="bg-white/50 rounded p-2">
                      <p className="text-foreground"><strong>Customer:</strong></p>
                      <p className="text-muted-foreground">Email: customer@eprinting.com</p>
                      <p className="text-muted-foreground">Password: customer123</p>
                    </div>
                  </div>
                </div>
              )}
            </div>

            {/* Error Message */}
            {error && (
              <div className="text-sm text-red-500 mt-3">
                {error}
              </div>
            )}
          </form>
        </div>
      </div>
    </div>
  );
}
