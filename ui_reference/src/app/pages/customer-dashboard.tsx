import { FormEvent, useEffect, useMemo, useState } from "react";
import { CalendarDays, Camera, CheckCircle2, Clock3, FileText, LogOut, PackageCheck, Printer, Upload, UserRound, Wallet } from "lucide-react";
import { useAuth } from "../contexts/auth-context";
import { CustomerRequest, PhotographyPackage, RequestKind, readPaperPrices, readPhotographyPackages, readPhotographyThemes, readRequests, requestReference, withStatus, writeRequests } from "../data/request-store";
import logo from "../../assets/jhunlea-printing-services-badge.png";

type Panel = "overview" | "printing" | "photography" | "printing-history" | "photography-history" | "history" | "notifications" | "profile";
const acceptedFileTypes = ".pdf,.doc,.docx,.ppt,.pptx,.jpg,.jpeg,.png,.webp";
const maxUploadBytes = 10 * 1024 * 1024;
const inputClass = "w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900 focus:border-[#C62828] focus:outline-none focus:ring-2 focus:ring-red-100";

function encodeFile(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onerror = () => reject(new Error("Hindi mabasa ang file."));
    reader.onload = () => resolve(String(reader.result));
    reader.readAsDataURL(file);
  });
}
function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return <label className="grid gap-1.5 text-sm font-medium text-gray-700">{label}{children}</label>;
}
function StatusPill({ status }: { status: string }) {
  const tone = status === "Rejected" || status === "Cancelled" ? "bg-red-50 text-red-700" : status === "Completed" || status === "Confirmed" || status === "Photoshoot Completed" ? "bg-green-50 text-green-700" : "bg-amber-50 text-amber-800";
  return <span className={`rounded-full px-3 py-1 text-xs font-semibold ${tone}`}>{status}</span>;
}

export function CustomerDashboard() {
  const { user, logout } = useAuth();
  const [panel, setPanel] = useState<Panel>("overview");
  const [requests, setRequests] = useState<CustomerRequest[]>([]);
  const [notice, setNotice] = useState("");
  const [includePhotoPrinting, setIncludePhotoPrinting] = useState(false);
  const [packages, setPackages] = useState<PhotographyPackage[]>(readPhotographyPackages());
  const [selectedPackageId, setSelectedPackageId] = useState(packages[0]?.id || "");
  const [themes, setThemes] = useState<string[]>(readPhotographyThemes());
  const [customTheme, setCustomTheme] = useState(false);
  const [otherPrintingService, setOtherPrintingService] = useState(false);

  useEffect(() => {
    const refresh = () => setRequests(readRequests().filter((request) => request.customerEmail === user?.email));
    refresh();
    window.addEventListener("storage", refresh);
    window.addEventListener("jhunlea-requests-updated", refresh);
    return () => {
      window.removeEventListener("storage", refresh);
      window.removeEventListener("jhunlea-requests-updated", refresh);
    };
  }, [user?.email]);
  useEffect(() => {
    const refreshPackages = () => { const next = readPhotographyPackages(); setPackages(next); if (!next.some((item) => item.id === selectedPackageId)) setSelectedPackageId(next[0]?.id || ""); };
    window.addEventListener("jhunlea-packages-updated", refreshPackages);
    window.addEventListener("storage", refreshPackages);
    return () => { window.removeEventListener("jhunlea-packages-updated", refreshPackages); window.removeEventListener("storage", refreshPackages); };
  }, [selectedPackageId]);
  useEffect(() => {
    const refreshThemes = () => setThemes(readPhotographyThemes());
    window.addEventListener("jhunlea-themes-updated", refreshThemes);
    window.addEventListener("storage", refreshThemes);
    return () => { window.removeEventListener("jhunlea-themes-updated", refreshThemes); window.removeEventListener("storage", refreshThemes); };
  }, []);

  const saveRequest = async (event: FormEvent<HTMLFormElement>, kind: RequestKind) => {
    event.preventDefault();
    setNotice("");
    const form = event.currentTarget;
    const data = new FormData(form);
    const upload = data.get("attachment");
    const reference = data.get("inspiration");
    const attachment = upload instanceof File && upload.size ? upload : undefined;
    const inspiration = reference instanceof File && reference.size ? reference : undefined;
    for (const file of [attachment, inspiration]) {
      if (file && file.size > maxUploadBytes) { setNotice("Limit ang file sa 10 MB bawat isa."); return; }
      if (file && !/\.(pdf|docx?|pptx?|jpe?g|png|webp)$/i.test(file.name)) { setNotice("PDF, Word, PowerPoint, JPG, PNG, o WEBP lang ang puwedeng i-upload."); return; }
    }
    try {
      const numeric = (key: string, fallback = 0) => Number(data.get(key) || fallback);
      const paperPrices = readPaperPrices();
      const fileData = attachment ? await encodeFile(attachment) : undefined;
      const inspirationFileData = inspiration ? await encodeFile(inspiration) : undefined;
      const includePrinting = kind === "photography" && data.get("includePrinting") === "yes";
      const copies = numeric("copies", 1);
      const printCount = numeric("printCount", 0);
      if (kind === "printing" && (!attachment || !Number.isInteger(copies) || copies < 1 || copies > 500)) {
        setNotice("Mag-upload ng file at pumili ng 1 hanggang 500 kopya."); return;
      }
      if (kind === "photography" && (!data.get("requestedDate") || !data.get("requestedTime"))) {
        setNotice("Piliin ang gustong petsa at oras ng photoshoot."); return;
      }
      if (kind === "photography" && includePrinting && (!Number.isInteger(printCount) || printCount < 1 || printCount > 500)) {
        setNotice("Pumili ng 1 hanggang 500 photo prints."); return;
      }
      const photographyFee = kind === "photography" ? numeric("packagePrice", 1500) : 0;
      const photoPrintPrice = paperPrices.find((paper) => paper.paperType === "Photo Paper" && paper.size === String(data.get("printSize")))?.price ?? (data.get("printSize") === "A4" ? 35 : 25);
      const printingFee = includePrinting ? Math.max(1, printCount) * photoPrintPrice : 0;
      const request: CustomerRequest = {
        id: requestReference(kind), kind, customerEmail: user!.email, customerName: user!.name,
        contactNumber: String(data.get("contactNumber") || user?.phone || ""), submittedAt: new Date().toISOString(),
        status: "Submitted", statusHistory: [{ status: "Submitted", at: new Date().toISOString() }],
        amountPaid: 0, payments: [], fileName: attachment?.name, fileData,
        service: String(data.get("service")) === "Other Printing Service" ? String(data.get("otherService") || "Other Printing Service") : String(data.get("service") || "Document Printing"), copies,
        paperSize: String(data.get("paperSize") || "A4"), paperType: String(data.get("paperType") || "Bond Paper"),
        colorMode: String(data.get("colorMode") || "Black and white"), sides: String(data.get("sides") || "Single-sided"),
        instructions: String(data.get("instructions") || ""),
        shootType: String(data.get("shootType") || "Portrait Session"), packageName: packages.find((item) => item.id === selectedPackageId)?.name || "Photography Package",
        people: numeric("people", 1), requestedDate: String(data.get("requestedDate") || ""), requestedTime: String(data.get("requestedTime") || ""),
        location: String(data.get("location") || ""), theme: customTheme ? String(data.get("customTheme") || "Custom Theme") : String(data.get("theme") || "Custom Theme"),
        background: String(data.get("background") || ""), colorMotif: String(data.get("colorMotif") || ""), outfitNotes: String(data.get("outfitNotes") || ""),
        inspirationFileName: inspiration?.name, inspirationFileData, includePrinting,
        printSize: includePrinting ? String(data.get("printSize") || "4R") : undefined,
        printCount: includePrinting ? printCount : undefined,
        photographyFee: kind === "photography" ? photographyFee : undefined,
        printingFee: kind === "photography" && includePrinting ? printingFee : undefined,
        printStatus: kind === "photography" && includePrinting ? "Submitted" : undefined,
        estimatedPrice: kind === "printing" ? undefined : photographyFee + printingFee,
      };
      if (kind === "printing") {
        const configuredPaper = paperPrices.find((paper) => paper.paperType === request.paperType && paper.size === request.paperSize)?.price ?? 0;
        const printServiceFee = request.colorMode === "Color" ? 10 : 3;
        const estimate = copies * (configuredPaper + printServiceFee);
        request.estimatedPrice = estimate;
      }
      const review = kind === "printing"
        ? `${request.service}\n${request.fileName}\n${request.copies} copies · ${request.paperSize} · ${request.paperType} · ${request.colorMode}\nEstimate: ₱${request.estimatedPrice?.toFixed(2)} (owner will confirm)`
        : `${request.shootType} · ${request.packageName}\n${request.theme}\nRequested: ${request.requestedDate} at ${request.requestedTime}\nPhotography estimate: ₱${photographyFee.toFixed(2)}\nPhoto printing: ${includePrinting ? `₱${printingFee.toFixed(2)}` : "No, digital photos only"}\nCombined estimate: ₱${(photographyFee + printingFee).toFixed(2)}`;
      if (!window.confirm(`Review your ${kind === "printing" ? "printing request" : "photography booking"} before submitting:\n\n${review}\n\nThe owner must review and confirm it. Payment is face-to-face.`)) return;
      writeRequests([request, ...readRequests()]);
      setRequests((current) => [request, ...current]);
      setPanel("history");
      setNotice(`Naipasa ang request ${request.id}. Susuriin muna ito ng shop bago tanggapin o kumpirmahin ang presyo.`);
      form.reset();
      setIncludePhotoPrinting(false);
      setCustomTheme(false);
      setOtherPrintingService(false);
    } catch {
      setNotice("Hindi na-save ang request. Baka puno na ang local browser storage; subukang gumamit ng mas maliit na file.");
    }
  };
  const acceptProposedSchedule = (id: string) => {
    const next = readRequests().map((request) => request.id === id && request.customerEmail === user?.email && request.status === "Reschedule Requested" ? withStatus(request, "Confirmed", "Customer accepted the proposed schedule.") : request);
    writeRequests(next);
    setRequests(next.filter((request) => request.customerEmail === user?.email));
  };
  const cancelRequest = (id: string) => {
    const next = readRequests().map((request) => request.id === id && request.customerEmail === user?.email && ["Submitted", "Under Review", "Awaiting Confirmation"].includes(request.status) ? withStatus(request, "Cancelled", "Cancelled by customer before processing.") : request);
    writeRequests(next);
    setRequests(next.filter((request) => request.customerEmail === user?.email));
  };

  const counts = useMemo(() => ({
    submitted: requests.length,
    review: requests.filter((request) => ["Submitted", "Under Review", "Awaiting Confirmation"].includes(request.status)).length,
    bookings: requests.filter((request) => request.kind === "photography" && request.status === "Confirmed").length,
    processing: requests.filter((request) => request.kind === "printing" && request.status === "Processing").length,
    ready: requests.filter((request) => request.status === "Ready for Pickup").length,
    unpaid: requests.filter((request) => (request.confirmedPrice ?? request.estimatedPrice ?? 0) > request.amountPaid).length,
  }), [requests]);
  const nav: [Panel, string, React.ReactNode][] = [
    ["overview", "Overview", <PackageCheck className="h-4 w-4" />],
    ["printing", "Request Printing", <Printer className="h-4 w-4" />],
    ["photography", "Book Photography", <Camera className="h-4 w-4" />],
    ["printing-history", "My Printing Orders", <Printer className="h-4 w-4" />],
    ["photography-history", "My Photography Bookings", <CalendarDays className="h-4 w-4" />],
    ["history", "My Requests", <FileText className="h-4 w-4" />],
    ["notifications", "Notifications", <Clock3 className="h-4 w-4" />],
    ["profile", "Profile", <UserRound className="h-4 w-4" />],
  ];

  return <div className="min-h-screen bg-gray-50 text-gray-900 md:flex">
    <aside className="w-full border-b border-gray-200 bg-white md:min-h-screen md:w-60 md:border-b-0 md:border-r">
      <div className="flex h-16 items-center gap-3 border-b px-5">
        <img src={logo} alt="Jhunleah Printing Services" className="h-12 w-12 rounded-full object-contain" />
        <span className="font-bold leading-tight">JHUNLEAH<small className="block text-[10px] font-medium tracking-wide text-gray-500">PRINTING SERVICES</small></span>
      </div>
      <nav className="flex gap-2 overflow-x-auto p-3 md:flex-col">
        {nav.map(([key, label, icon]) => <button key={key} onClick={() => { setPanel(key); setNotice(""); }} className={`flex shrink-0 items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm font-medium ${panel === key ? "bg-red-50 text-[#C62828]" : "text-gray-600 hover:bg-gray-100"}`}>{icon}{label}</button>)}
      </nav>
      <button onClick={logout} className="hidden items-center gap-3 border-t px-5 py-4 text-sm text-gray-600 hover:text-[#C62828] md:mt-[calc(100vh-360px)] md:flex"><LogOut className="h-4 w-4" />Sign out</button>
    </aside>
    <main className="min-w-0 flex-1 p-4 md:p-8">
      <header className="mb-7 flex items-center justify-between gap-4"><div><p className="text-sm text-gray-500">Customer Portal</p><h1 className="text-2xl font-bold">{panel === "overview" ? `Hello, ${user?.name?.split(" ")[0] || "Customer"}` : nav.find(([key]) => key === panel)?.[1]}</h1></div><button onClick={logout} className="rounded-lg border bg-white px-3 py-2 text-sm md:hidden">Sign out</button></header>
      {notice && <div className="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{notice}</div>}

      {panel === "overview" && <>
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">{[["Requests", counts.submitted, FileText], ["Awaiting review", counts.review, Clock3], ["Confirmed bookings", counts.bookings, CalendarDays], ["Unpaid balances", counts.unpaid, Wallet]].map(([label, value, Icon]: any) => <div key={label} className="rounded-xl border bg-white p-4"><div className="mb-3 flex items-center justify-between text-sm text-gray-500"><span>{label}</span><Icon className="h-4 w-4 text-[#C62828]" /></div><p className="text-2xl font-bold">{value}</p></div>)}</div>
        <div className="mt-7 grid gap-4 md:grid-cols-2"><button onClick={() => setPanel("printing")} className="rounded-xl border bg-white p-6 text-left shadow-sm transition hover:border-red-300"><Printer className="mb-4 h-7 w-7 text-[#C62828]" /><h2 className="font-semibold">Request Printing</h2><p className="mt-1 text-sm text-gray-500">Send files and print specifications for owner review.</p></button><button onClick={() => setPanel("photography")} className="rounded-xl border bg-white p-6 text-left shadow-sm transition hover:border-red-300"><Camera className="mb-4 h-7 w-7 text-[#C62828]" /><h2 className="font-semibold">Book Photography</h2><p className="mt-1 text-sm text-gray-500">Request a photoshoot and choose an optional print package.</p></button></div>
        <div className="mt-7 rounded-xl border bg-white p-5"><h2 className="mb-3 font-semibold">Recent requests</h2><RequestList requests={requests.slice(0, 4)} onAcceptSchedule={acceptProposedSchedule} onCancel={cancelRequest} /></div>
      </>}

      {panel === "printing" && <form onSubmit={(event) => void saveRequest(event, "printing")} className="max-w-4xl rounded-xl border bg-white p-5 shadow-sm md:p-7">
        <p className="mb-5 text-sm text-gray-500">Ipapasa ito sa Jhunlea para sa review at kumpirmasyon ng presyo. Hindi pa ito awtomatikong tatanggapin.</p>
        <div className="grid gap-4 md:grid-cols-2">
          <Field label="Printing service"><select name="service" className={inputClass} onChange={(event) => setOtherPrintingService(event.target.value === "Other Printing Service")}><option>Document Printing</option><option>Photocopying</option><option>Photo Printing</option><option>ID / Passport Picture Printing</option><option>Other Printing Service</option></select></Field>
          {otherPrintingService && <Field label="Describe the printing service"><input className={inputClass} name="otherService" placeholder="What would you like printed?" required /></Field>}
          <Field label="File to print"><input className={inputClass} name="attachment" type="file" accept={acceptedFileTypes} required /></Field>
          <Field label="Copies"><input className={inputClass} name="copies" type="number" min="1" max="500" defaultValue="1" required /></Field>
          <Field label="Paper size"><select className={inputClass} name="paperSize"><option>Short</option><option>Long</option><option>A4</option><option>Letter</option><option>Legal</option><option>4R</option><option>5R</option></select></Field>
          <Field label="Paper type"><select className={inputClass} name="paperType"><option>Bond Paper</option><option>Glossy</option><option>Matte</option><option>Photo Paper</option><option>Other (confirm with owner)</option></select></Field>
          <Field label="Print color"><select className={inputClass} name="colorMode"><option>Black and white</option><option>Color</option></select></Field>
          <Field label="Sides"><select className={inputClass} name="sides"><option>Single-sided</option><option>Double-sided</option></select></Field>
          <Field label="Contact number"><input className={inputClass} name="contactNumber" type="tel" defaultValue={user?.phone} required /></Field>
          <div className="md:col-span-2"><Field label="Special instructions"><textarea className={inputClass} name="instructions" rows={3} placeholder="Binding, deadline, or other preferences" /></Field></div>
        </div><p className="my-4 text-sm text-gray-500">Payment is face-to-face at Jhunlea Photography and Printing. The owner will confirm the final price.</p><button className="rounded-lg bg-[#C62828] px-5 py-3 text-sm font-semibold text-white hover:bg-[#A51F1F]">Submit printing request</button>
      </form>}

      {panel === "photography" && <form onSubmit={(event) => void saveRequest(event, "photography")} className="max-w-4xl rounded-xl border bg-white p-5 shadow-sm md:p-7">
        <p className="mb-5 text-sm text-gray-500">Pinili mo pa lang ang schedule; magiging confirmed lang kapag kinumpirma ng owner.</p>
        <div className="grid gap-4 md:grid-cols-2">
          <Field label="Contact number"><input className={inputClass} name="contactNumber" type="tel" defaultValue={user?.phone} required /></Field>
          <Field label="Photoshoot service"><select className={inputClass} name="shootType"><option>Portrait Session</option><option>Family Photoshoot</option><option>Graduation Photoshoot</option><option>Passport / ID Session</option><option>Event Photography</option><option>Custom Photoshoot</option></select></Field>
          <Field label="Package"><select className={inputClass} name="packageName" value={selectedPackageId} onChange={(event) => setSelectedPackageId(event.target.value)}>{packages.map((item) => <option key={item.id} value={item.id}>{item.name} — ₱{item.price.toLocaleString()} estimate</option>)}</select><input name="packagePrice" type="hidden" value={packages.find((item) => item.id === selectedPackageId)?.price ?? 0} /></Field>
          <Field label="Number of people"><input className={inputClass} name="people" type="number" min="1" max="50" defaultValue="1" /></Field>
          <Field label="Preferred date"><input className={inputClass} name="requestedDate" type="date" min={new Date().toISOString().slice(0, 10)} required /></Field>
          <Field label="Preferred time"><select className={inputClass} name="requestedTime" required><option value="">Select a time</option><option>9:00 AM</option><option>10:00 AM</option><option>11:00 AM</option><option>1:00 PM</option><option>2:00 PM</option><option>3:00 PM</option><option>4:00 PM</option></select></Field>
          <Field label="Preferred location"><input className={inputClass} name="location" placeholder="Studio or preferred venue" /></Field>
          <Field label="Theme"><select className={inputClass} name="theme" onChange={(event) => setCustomTheme(event.target.value === "Other / Custom Theme")}><option value="">Choose a theme or custom concept</option>{themes.map((theme) => <option key={theme}>{theme}</option>)}<option>Other / Custom Theme</option></select></Field>
          {customTheme && <Field label="Describe your custom theme"><input className={inputClass} name="customTheme" placeholder="Describe your preferred concept" required /></Field>}
          <Field label="Background / setup"><input className={inputClass} name="background" placeholder="e.g. plain white, floral" /></Field>
          <Field label="Color motif"><input className={inputClass} name="colorMotif" placeholder="Optional" /></Field>
          <Field label="Outfit or styling notes"><input className={inputClass} name="outfitNotes" placeholder="Optional" /></Field>
          <Field label="Reference / inspiration image"><input className={inputClass} name="inspiration" type="file" accept="image/jpeg,image/png,image/webp" /></Field>
          <div className="md:col-span-2"><Field label="Creative instructions"><textarea className={inputClass} name="instructions" rows={3} placeholder="Describe your preferred concept" /></Field></div>
          <div className="md:col-span-2 rounded-lg border border-gray-200 p-4"><p className="mb-3 font-semibold">Do you want to have your photos printed as well?</p><div className="flex flex-wrap gap-5 text-sm"><label className="flex items-center gap-2"><input type="radio" name="includePrinting" value="no" checked={!includePhotoPrinting} onChange={() => setIncludePhotoPrinting(false)} />No, digital photos only</label><label className="flex items-center gap-2"><input type="radio" name="includePrinting" value="yes" checked={includePhotoPrinting} onChange={() => setIncludePhotoPrinting(true)} />Yes, include photo printing</label></div>{includePhotoPrinting && <div className="mt-4 grid gap-4 sm:grid-cols-2"><Field label="Print size"><select className={inputClass} name="printSize"><option>4R</option><option>5R</option><option>A4</option></select></Field><Field label="Number of prints"><input className={inputClass} name="printCount" type="number" min="1" max="500" defaultValue="1" required /></Field></div>}<p className="mt-3 text-xs text-gray-500">Ang print fee ay hiwalay na estimate; puwedeng baguhin ng owner sa final quotation.</p></div>
        </div><p className="my-4 text-sm text-gray-500">Payment is face-to-face at Jhunlea Photography and Printing. Walang online payment o awtomatikong schedule confirmation.</p><button className="rounded-lg bg-[#C62828] px-5 py-3 text-sm font-semibold text-white hover:bg-[#A51F1F]">Submit photography booking</button>
      </form>}

      {panel === "history" && <div className="rounded-xl border bg-white p-5"><RequestList requests={requests} onAcceptSchedule={acceptProposedSchedule} onCancel={cancelRequest} /></div>}
      {panel === "printing-history" && <div className="rounded-xl border bg-white p-5"><RequestList requests={requests.filter((request) => request.kind === "printing")} onAcceptSchedule={acceptProposedSchedule} onCancel={cancelRequest} /></div>}
      {panel === "photography-history" && <div className="rounded-xl border bg-white p-5"><RequestList requests={requests.filter((request) => request.kind === "photography")} onAcceptSchedule={acceptProposedSchedule} onCancel={cancelRequest} /></div>}
      {panel === "notifications" && <div className="rounded-xl border bg-white p-5"><h2 className="mb-4 font-semibold">Request updates</h2>{requests.flatMap((request) => request.statusHistory.slice(1).map((update, index) => ({ request, update, index }))).reverse().length ? <div className="divide-y">{requests.flatMap((request) => request.statusHistory.slice(1).map((update, index) => ({ request, update, index }))).reverse().map(({ request, update, index }) => <div key={`${request.id}-${index}`} className="py-3"><p className="font-medium">{request.id} · {update.status}</p><p className="text-sm text-gray-500">{new Date(update.at).toLocaleString()} {update.note ? `— ${update.note}` : ""}</p></div>)}</div> : <p className="py-8 text-center text-sm text-gray-500">Wala pang updates mula sa shop.</p>}</div>}
      {panel === "profile" && <div className="max-w-xl rounded-xl border bg-white p-6"><h2 className="mb-4 font-semibold">My profile</h2><dl className="grid grid-cols-[150px_1fr] gap-3 text-sm"><dt className="text-gray-500">Name</dt><dd>{user?.name}</dd><dt className="text-gray-500">Email</dt><dd>{user?.email}</dd><dt className="text-gray-500">Contact</dt><dd>{user?.phone || "Not provided"}</dd><dt className="text-gray-500">Payment</dt><dd>Face-to-face at Jhunlea Photography and Printing</dd></dl></div>}
    </main>
  </div>;
}

function RequestList({ requests, onAcceptSchedule, onCancel }: { requests: CustomerRequest[]; onAcceptSchedule?: (id: string) => void; onCancel?: (id: string) => void }) {
  if (!requests.length) return <div className="py-12 text-center text-sm text-gray-500"><Upload className="mx-auto mb-3 h-7 w-7 text-gray-400" />Wala ka pang request. Pumili ng printing service o mag-book ng photography.</div>;
  return <div className="divide-y">{requests.map((request) => {
    const totalDue = request.confirmedPrice ?? request.estimatedPrice ?? 0;
    const balance = Math.max(0, totalDue - request.amountPaid);
    return <article key={request.id} className="py-4 first:pt-0 last:pb-0"><div className="flex flex-wrap items-start justify-between gap-3"><div><p className="font-semibold">{request.id} · {request.kind === "printing" ? request.service : request.shootType}</p><p className="mt-1 text-xs text-gray-500">Submitted {new Date(request.submittedAt).toLocaleString()}</p></div><StatusPill status={request.status} /></div><div className="mt-3 grid gap-2 text-sm text-gray-600 sm:grid-cols-2"><p>{request.kind === "printing" ? `${request.copies} copies · ${request.paperSize} · ${request.colorMode}` : `${request.theme} · Requested: ${request.requestedDate} ${request.requestedTime}`}</p><p>Price: {request.confirmedPrice != null ? `₱${request.confirmedPrice.toFixed(2)} confirmed` : request.estimatedPrice != null ? `₱${request.estimatedPrice.toFixed(2)} estimate` : "For quotation"}</p><p>Payment: {request.amountPaid <= 0 ? "Unpaid" : balance ? "Partially Paid" : "Paid"} · Paid ₱{request.amountPaid.toFixed(2)} of ₱{totalDue.toFixed(2)} · Balance ₱{balance.toFixed(2)}</p>{request.confirmedDate && <p>{request.status === "Reschedule Requested" ? "Proposed schedule" : "Confirmed schedule"}: {request.confirmedDate} {request.confirmedTime}</p>}{request.printStatus && <p>Photo print production: {request.printStatus}</p>}</div>{request.status === "Reschedule Requested" && onAcceptSchedule && <button onClick={() => onAcceptSchedule(request.id)} className="mt-3 rounded-lg bg-[#C62828] px-4 py-2 text-sm font-semibold text-white">Accept proposed schedule</button>}{!["Submitted", "Under Review", "Awaiting Confirmation"].includes(request.status) ? null : onCancel && <button onClick={() => onCancel(request.id)} className="mt-3 ml-2 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700">Cancel request</button>}{request.ownerNote && <p className="mt-3 rounded-lg bg-gray-50 p-3 text-sm">Shop update: {request.ownerNote}</p>}{request.rejectionReason && <p className="mt-2 text-sm text-red-700">Reason: {request.rejectionReason}</p>}{request.statusHistory.length > 1 && <details className="mt-2 text-xs text-gray-500"><summary className="cursor-pointer">Status history</summary><ol className="mt-2 space-y-1">{request.statusHistory.map((entry, index) => <li key={`${entry.at}-${index}`}>{new Date(entry.at).toLocaleString()} — {entry.status}{entry.note ? `: ${entry.note}` : ""}</li>)}</ol></details>}</article>;
  })}</div>;
}
