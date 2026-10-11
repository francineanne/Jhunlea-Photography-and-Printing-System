import { Link } from "react-router";
import { Printer, ShieldCheck, FileText, CheckCircle } from "lucide-react";
import logo from "../../assets/jhunlea-printing-services-badge.png";

export function LandingPage() {
  return (
    <div className="min-h-screen bg-gradient-to-br from-slate-50 to-gray-50">
      {/* Header/Navbar */}
      <header className="bg-white shadow-sm sticky top-0 z-50">
        <nav className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <img src={logo} alt="Jhunlea Photography and Printing" className="h-12 w-auto" />
          </div>
          <div className="hidden md:flex items-center gap-8">
            <a href="#home" className="text-foreground hover:text-primary transition-colors">
              Home
            </a>
            <a href="#features" className="text-foreground hover:text-primary transition-colors">
              Features
            </a>
            <a href="#contact" className="text-foreground hover:text-primary transition-colors">
              Contact
            </a>
          </div>
          <div>
            <Link
              to="/login"
              className="bg-primary text-primary-foreground px-6 py-2 rounded-lg hover:bg-primary/90 transition-colors"
            >
              Login
            </Link>
          </div>
        </nav>
      </header>

      {/* Hero Section */}
      <section id="home" className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-20 pb-24">
        <div className="text-center max-w-4xl mx-auto">
          <h1 className="text-5xl md:text-6xl mb-6 text-primary">
            Modern Printing Made Easy
          </h1>
          <p className="text-xl text-muted-foreground mb-8 max-w-2xl mx-auto">
            Book a photoshoot or send your print request to Jhunlea Photography & Printing in
            Calbayog City. Track updates while our team reviews your request.
          </p>
          <div className="flex flex-col sm:flex-row gap-4 justify-center">
            <Link
              to="/login"
              className="bg-primary text-primary-foreground px-8 py-4 rounded-lg hover:bg-primary/90 transition-all shadow-lg hover:shadow-xl"
            >
              Get Started
            </Link>
            <Link
              to="/register"
              className="bg-accent text-accent-foreground px-8 py-4 rounded-lg hover:bg-accent/90 transition-all shadow-lg hover:shadow-xl"
            >
              Register Now
            </Link>
          </div>
        </div>

        {/* Logo Image */}
        <div className="mt-16 flex justify-center">
          <img src={logo} alt="Jhunlea Photography and Printing" className="w-72 h-auto" />
        </div>
      </section>

      {/* Features Section */}
      <section id="features" className="bg-white py-20">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="text-center mb-16">
            <h2 className="text-4xl text-primary mb-4">Powerful Features</h2>
            <p className="text-muted-foreground max-w-2xl mx-auto">
              Everything you need to manage your printing business efficiently
            </p>
          </div>

          <div className="grid md:grid-cols-3 gap-8">
            {/* Feature 1 */}
            <div className="bg-gradient-to-br from-gray-50 to-white p-8 rounded-xl shadow-md hover:shadow-xl transition-shadow">
              <div className="bg-primary text-primary-foreground w-16 h-16 rounded-lg flex items-center justify-center mb-6">
                <FileText className="w-8 h-8" />
              </div>
              <h3 className="text-2xl text-primary mb-3">Easy Order Management</h3>
              <p className="text-muted-foreground">
                Track and manage all your printing orders in one centralized dashboard. Real-time
                updates and notifications keep you informed.
              </p>
            </div>

            {/* Feature 2 */}
            <div className="bg-gradient-to-br from-gray-50 to-white p-8 rounded-xl shadow-md hover:shadow-xl transition-shadow">
              <div className="bg-secondary text-secondary-foreground w-16 h-16 rounded-lg flex items-center justify-center mb-6">
                <ShieldCheck className="w-8 h-8" />
              </div>
              <h3 className="text-2xl text-primary mb-3">Secure Transactions</h3>
              <p className="text-muted-foreground">
                Built with security in mind. All transactions are encrypted and protected, ensuring
                your business data stays safe.
              </p>
            </div>

            {/* Feature 3 */}
            <div className="bg-gradient-to-br from-orange-50 to-white p-8 rounded-xl shadow-md hover:shadow-xl transition-shadow">
              <div className="bg-accent text-accent-foreground w-16 h-16 rounded-lg flex items-center justify-center mb-6">
                <CheckCircle className="w-8 h-8" />
              </div>
              <h3 className="text-2xl text-primary mb-3">Verified Print Shops</h3>
              <p className="text-muted-foreground">
                Only verified and trusted print shops. Build customer confidence with our
                verification system and quality assurance.
              </p>
            </div>
          </div>
        </div>
      </section>

      {/* Footer */}
      <footer id="contact" className="bg-primary text-primary-foreground py-12">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="grid md:grid-cols-3 gap-8 mb-8">
            <div>
              <h4 className="mb-4">JHUNLEA Photography & Printing</h4>
              <p className="text-primary-foreground/80">
                Modernizing print shops in Calbayog City
              </p>
            </div>
            <div>
              <h4 className="mb-4">Contact</h4>
              <p className="text-primary-foreground/80">Email: info@eprinting.com</p>
              <p className="text-primary-foreground/80">Phone: +63 123 456 7890</p>
            </div>
            <div>
              <h4 className="mb-4">Location</h4>
              <p className="text-primary-foreground/80">Calbayog City</p>
              <p className="text-primary-foreground/80">Samar, Philippines</p>
            </div>
          </div>
          <div className="border-t border-primary-foreground/20 pt-8 text-center text-primary-foreground/80">
            <p>&copy; 2026 JHUNLEA Photography & Printing. All rights reserved.</p>
          </div>
        </div>
      </footer>
    </div>
  );
}
