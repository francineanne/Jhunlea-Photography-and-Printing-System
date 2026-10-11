import { FormEvent, useState } from "react";
import { Link } from "react-router";
import { Camera, Lock, Mail, Phone, User } from "lucide-react";
import logo from "../../assets/jhunlea-printing-services-badge.png";
import { useAuth } from "../contexts/auth-context";

export function RegisterPage() {
  const { register } = useAuth();
  const [error, setError] = useState("");
  const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError("");
    const form = new FormData(event.currentTarget);
    const password = String(form.get("password"));
    if (password !== form.get("confirmPassword")) { setError("Hindi magkapareho ang password at confirmation."); return; }
    const saved = register(String(form.get("email")), password, String(form.get("name")), String(form.get("phone")));
    if (!saved) setError("May account na gamit ang email na ito.");
  };
  return <div className="min-h-screen lg:flex">
    <section className="hidden items-center justify-center bg-[#171717] p-12 text-white lg:flex lg:w-1/2"><div className="max-w-md text-center"><img src={logo} alt="Jhunlea Photography and Printing" className="mx-auto mb-8 w-80" /><Camera className="mx-auto mb-4 h-10 w-10 text-[#C62828]" /><h1 className="mb-4 text-4xl font-bold">Create your customer account</h1><p className="text-white/75">Request printing, book a photoshoot, and follow every update from Jhunlea.</p></div></section>
    <section className="flex w-full items-center justify-center bg-white p-6 lg:w-1/2"><div className="w-full max-w-md py-6"><img src={logo} alt="Jhunlea Photography and Printing" className="mx-auto mb-7 w-48 lg:hidden" /><h2 className="mb-2 text-3xl font-bold text-[#171717]">Create Account</h2><p className="mb-7 text-sm text-gray-500">Register as a customer to get started.</p>
      <form onSubmit={handleSubmit} className="space-y-4">
        {error && <p role="alert" className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">{error}</p>}
        <label className="grid gap-1.5 text-sm font-medium text-gray-700">Full name<div className="relative"><User className="absolute left-3 top-3 h-4 w-4 text-gray-400" /><input required name="name" autoComplete="name" className="w-full rounded-lg border px-10 py-2.5" placeholder="Juan Dela Cruz" /></div></label>
        <label className="grid gap-1.5 text-sm font-medium text-gray-700">Email address<div className="relative"><Mail className="absolute left-3 top-3 h-4 w-4 text-gray-400" /><input required type="email" name="email" autoComplete="email" className="w-full rounded-lg border px-10 py-2.5" placeholder="you@example.com" /></div></label>
        <label className="grid gap-1.5 text-sm font-medium text-gray-700">Contact number<div className="relative"><Phone className="absolute left-3 top-3 h-4 w-4 text-gray-400" /><input required type="tel" name="phone" autoComplete="tel" className="w-full rounded-lg border px-10 py-2.5" placeholder="09xx xxx xxxx" /></div></label>
        <label className="grid gap-1.5 text-sm font-medium text-gray-700">Password<div className="relative"><Lock className="absolute left-3 top-3 h-4 w-4 text-gray-400" /><input required minLength={8} type="password" name="password" autoComplete="new-password" className="w-full rounded-lg border px-10 py-2.5" /></div></label>
        <label className="grid gap-1.5 text-sm font-medium text-gray-700">Confirm password<div className="relative"><Lock className="absolute left-3 top-3 h-4 w-4 text-gray-400" /><input required minLength={8} type="password" name="confirmPassword" autoComplete="new-password" className="w-full rounded-lg border px-10 py-2.5" /></div></label>
        <button className="w-full rounded-lg bg-[#C62828] py-3 font-semibold text-white hover:bg-[#A51F1F]">Create Customer Account</button>
      </form>
      <p className="mt-5 text-center text-sm text-gray-600">May account ka na? <Link to="/login" className="font-medium text-[#C62828] hover:underline">Sign in</Link></p>
    </div></section>
  </div>;
}
