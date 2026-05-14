import { Link, Route, Routes } from "react-router-dom";
import ScannerDashboard from "./pages/ScannerDashboard";
import StudentPass from "./pages/StudentPass";

export default function App() {
  return (
    <div className="min-h-screen">
      <header className="border-b border-zinc-800 bg-zinc-950/70 backdrop-blur">
        <div className="mx-auto flex max-w-6xl items-center justify-between px-4 py-3">
          <div className="font-semibold tracking-tight">Event Entry</div>
          <nav className="flex gap-4 text-sm text-zinc-300">
            <Link className="hover:text-white" to="/scanner">
              Scanner
            </Link>
            <Link className="hover:text-white" to="/pass">
              Student Pass
            </Link>
          </nav>
        </div>
      </header>

      <main className="mx-auto max-w-6xl px-4 py-6">
        <Routes>
          <Route path="/" element={<ScannerDashboard />} />
          <Route path="/scanner" element={<ScannerDashboard />} />
          <Route path="/pass" element={<StudentPass />} />
        </Routes>
      </main>
    </div>
  );
}

