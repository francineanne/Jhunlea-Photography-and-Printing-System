export type RequestKind = "printing" | "photography";
export type RequestStatus = "Submitted" | "Under Review" | "Accepted" | "Rejected" | "Processing" | "Ready for Pickup" | "Completed" | "Cancelled" | "Awaiting Confirmation" | "Confirmed" | "Reschedule Requested" | "Photoshoot Completed";
export interface CustomerRequest {
  id: string;
  kind: RequestKind;
  customerEmail: string;
  customerName: string;
  contactNumber: string;
  submittedAt: string;
  status: RequestStatus;
  statusHistory: { status: RequestStatus; at: string; note?: string }[];
  ownerNote?: string;
  rejectionReason?: string;
  estimatedPrice?: number;
  confirmedPrice?: number;
  amountPaid: number;
  payments: { amount: number; date: string; method: string; recordedBy: string }[];
  fileName?: string;
  fileData?: string;
  service?: string;
  copies?: number;
  paperSize?: string;
  paperType?: string;
  colorMode?: string;
  sides?: string;
  instructions?: string;
  shootType?: string;
  packageName?: string;
  people?: number;
  requestedDate?: string;
  requestedTime?: string;
  confirmedDate?: string;
  confirmedTime?: string;
  location?: string;
  theme?: string;
  background?: string;
  colorMotif?: string;
  outfitNotes?: string;
  inspirationFileName?: string;
  inspirationFileData?: string;
  includePrinting?: boolean;
  printSize?: string;
  printCount?: number;
  photographyFee?: number;
  printingFee?: number;
  printStatus?: "Submitted" | "Processing" | "Ready for Pickup" | "Completed";
}

const REQUESTS_KEY = "jhunlea-requests-v1";
export interface PhotographyPackage { id: string; name: string; price: number; description: string }
const defaultPackages: PhotographyPackage[] = [
  { id: "essential", name: "Essential", price: 1500, description: "Studio portrait session" },
  { id: "classic", name: "Classic", price: 2500, description: "Extended session and more edited images" },
  { id: "signature", name: "Signature", price: 4000, description: "Premium creative session" },
];
export function readPhotographyPackages(): PhotographyPackage[] {
  try { return JSON.parse(localStorage.getItem("jhunlea-photo-packages-v1") || "null") || defaultPackages; }
  catch { return defaultPackages; }
}
export function writePhotographyPackages(packages: PhotographyPackage[]) {
  localStorage.setItem("jhunlea-photo-packages-v1", JSON.stringify(packages));
  window.dispatchEvent(new CustomEvent("jhunlea-packages-updated"));
}
const defaultThemes = ["Classic Studio", "Graduation", "Family Portrait", "Minimalist"];
export function readPhotographyThemes(): string[] {
  try { return JSON.parse(localStorage.getItem("jhunlea-photo-themes-v1") || "null") || defaultThemes; }
  catch { return defaultThemes; }
}
export function writePhotographyThemes(themes: string[]) {
  localStorage.setItem("jhunlea-photo-themes-v1", JSON.stringify(themes));
  window.dispatchEvent(new CustomEvent("jhunlea-themes-updated"));
}
export interface PaperPrice { id: number; paperType: string; size: string; price: number }
const defaultPaperPrices: PaperPrice[] = [
  { id: 1, paperType: "Glossy", size: "A4", price: 15 }, { id: 2, paperType: "Matte", size: "A4", price: 12 },
  { id: 3, paperType: "Glossy", size: "Letter", price: 16 }, { id: 4, paperType: "Matte", size: "Letter", price: 13 },
  { id: 5, paperType: "Bond Paper", size: "Short", price: 5 }, { id: 6, paperType: "Bond Paper", size: "Long", price: 7 },
  { id: 7, paperType: "Photo Paper", size: "4R", price: 25 }, { id: 8, paperType: "Photo Paper", size: "A4", price: 35 },
];
export function readPaperPrices(): PaperPrice[] {
  try { return JSON.parse(localStorage.getItem("jhunlea-paper-prices-v1") || "null") || defaultPaperPrices; }
  catch { return defaultPaperPrices; }
}
export function writePaperPrices(prices: PaperPrice[]) {
  localStorage.setItem("jhunlea-paper-prices-v1", JSON.stringify(prices));
  window.dispatchEvent(new CustomEvent("jhunlea-paper-prices-updated"));
}
export function readRequests(): CustomerRequest[] {
  try { return JSON.parse(localStorage.getItem(REQUESTS_KEY) || "[]") as CustomerRequest[]; }
  catch { return []; }
}
export function writeRequests(requests: CustomerRequest[]) {
  localStorage.setItem(REQUESTS_KEY, JSON.stringify(requests));
  window.dispatchEvent(new CustomEvent("jhunlea-requests-updated"));
}
export function requestReference(kind: RequestKind) {
  const prefix = kind === "printing" ? "PRN" : "PHO";
  return `${prefix}-${Date.now().toString().slice(-8)}-${Math.random().toString(36).slice(2, 5).toUpperCase()}`;
}
export function withStatus(request: CustomerRequest, status: RequestStatus, note?: string): CustomerRequest {
  const at = new Date().toISOString();
  return { ...request, status, ownerNote: note ?? request.ownerNote, statusHistory: [...request.statusHistory, { status, at, note }] };
}
