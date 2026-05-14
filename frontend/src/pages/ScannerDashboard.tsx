import { useEffect, useMemo, useRef, useState } from "react";
import { Html5Qrcode } from "html5-qrcode";
import { confirmEntry, validateQr } from "../lib/api";

type VerifyState =
  | { kind: "idle" }
  | { kind: "scanning" }
  | { kind: "loading" }
  | {
      kind: "result";
      ok: boolean;
      title: string;
      subtitle?: string;
      token?: string;
      details?: {
        student: {
          full_name: string;
          enrollment: string;
          department: string;
          photo_url: string;
        };
        event: { name: string; venue: string; event_date: string };
        ticket: { code: string };
      };
    };

export default function ScannerDashboard() {
  const scannerRef = useRef<Html5Qrcode | null>(null);
  const [state, setState] = useState<VerifyState>({ kind: "idle" });
  const [cameraError, setCameraError] = useState<string>("");
  const lastTokenRef = useRef<string>("");
  const stateKindRef = useRef<VerifyState["kind"]>("idle");

  const canScan = useMemo(() => state.kind === "scanning", [state.kind]);

  useEffect(() => {
    stateKindRef.current = state.kind;
  }, [state.kind]);

  useEffect(() => {
    const id = "qr-reader";
    const scanner = new Html5Qrcode(id, /* verbose= */ false);
    scannerRef.current = scanner;

    async function start() {
      setState({ kind: "scanning" });
      try {
        await scanner.start(
          { facingMode: "environment" },
          {
            fps: 15,
            qrbox: { width: 280, height: 280 },
            disableFlip: false,
          },
          async (decodedText) => {
            if (stateKindRef.current !== "scanning") return;
            if (!decodedText || decodedText === lastTokenRef.current) return;
            lastTokenRef.current = decodedText;
            setState({ kind: "loading" });
            const res = await validateQr(decodedText);

            if (res.status !== "ok") {
              setState({
                kind: "result",
                ok: false,
                title:
                  res.message === "QR_EXPIRED"
                    ? "QR EXPIRED"
                    : res.message === "ENTRY_ALREADY_USED"
                      ? "ENTRY ALREADY USED"
                      : "REJECTED",
                subtitle: String(res.message),
              });
              return;
            }

            setState({
              kind: "result",
              ok: true,
              title: "VALID",
              subtitle: "Verify photo and confirm entry",
              token: res.token,
              details: {
                student: res.student,
                event: res.event,
                ticket: { code: res.ticket.code },
              },
            });
          },
          () => {
            // ignore decode errors to keep scanning responsive
          }
        );
      } catch (e) {
        setCameraError(
          e instanceof Error ? e.message : "Failed to access camera."
        );
        setState({ kind: "idle" });
      }
    }

    start();

    return () => {
      scanner
        .stop()
        .catch(() => {})
        .finally(() => {
          scanner.clear().catch(() => {});
        });
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function resumeScan() {
    lastTokenRef.current = "";
    setState({ kind: "scanning" });
  }

  async function acceptEntry() {
    if (state.kind !== "result" || !state.ok || !state.token) return;
    const res = await confirmEntry(state.token);
    if (res.status !== "ok") {
      setState({
        kind: "result",
        ok: false,
        title:
          res.message === "ENTRY_ALREADY_USED" ? "ENTRY ALREADY USED" : "REJECTED",
        subtitle: String(res.message),
      });
      return;
    }
    setState({
      kind: "result",
      ok: true,
      title: "ENTRY ACCEPTED",
      subtitle: `Logged at ${res.used_at}`,
      details: state.details,
    });
    window.setTimeout(() => resumeScan(), 1200);
  }

  return (
    <div className="grid gap-6 lg:grid-cols-[1fr_420px]">
      <section className="rounded-2xl border border-zinc-800 bg-zinc-900/40 p-4">
        <div className="flex items-center justify-between">
          <h1 className="text-xl font-semibold">Scanner Dashboard</h1>
          <div className="text-xs text-zinc-400">
            Admin session required for validation
          </div>
        </div>

        {cameraError ? (
          <div className="mt-4 rounded-xl border border-red-800 bg-red-950 p-4 text-red-200">
            {cameraError}
          </div>
        ) : null}

        <div className="mt-4 overflow-hidden rounded-xl border border-zinc-800 bg-black">
          <div id="qr-reader" className="min-h-[340px] w-full" />
        </div>

        <div className="mt-4 text-sm text-zinc-300">
          Status:{" "}
          <span className="font-mono text-white">{state.kind}</span>
        </div>
      </section>

      <aside className="rounded-2xl border border-zinc-800 bg-zinc-900/40 p-5">
        {state.kind === "result" ? (
          <div
            className={`rounded-xl border p-4 ${
              state.ok
                ? "border-emerald-700 bg-emerald-950"
                : "border-red-700 bg-red-950"
            }`}
          >
            <div className="text-lg font-semibold">{state.title}</div>
            {state.subtitle ? (
              <div className="mt-1 text-sm text-zinc-200">{state.subtitle}</div>
            ) : null}

            {state.ok && state.details ? (
              <div className="mt-4 grid gap-3">
                <div className="flex items-center gap-3">
                  <div className="h-16 w-16 rounded-lg bg-zinc-800">
                    {state.details.student.photo_url ? (
                      <img
                        src={state.details.student.photo_url}
                        alt="Student"
                        className="h-16 w-16 rounded-lg object-cover"
                      />
                    ) : (
                      <div className="flex h-16 w-16 items-center justify-center text-xs text-zinc-400">
                        PHOTO
                      </div>
                    )}
                  </div>
                  <div>
                    <div className="font-semibold">
                      {state.details.student.full_name}
                    </div>
                    <div className="text-sm text-zinc-200">
                      {state.details.student.enrollment}
                    </div>
                    {state.details.student.department ? (
                      <div className="text-xs text-zinc-300">
                        {state.details.student.department}
                      </div>
                    ) : null}
                  </div>
                </div>

                <div className="rounded-lg border border-zinc-800 bg-zinc-950 p-3 text-sm">
                  <div className="text-zinc-300">
                    <span className="text-zinc-500">Event:</span>{" "}
                    {state.details.event.name}
                  </div>
                  <div className="text-zinc-300">
                    <span className="text-zinc-500">Venue:</span>{" "}
                    {state.details.event.venue}
                  </div>
                  <div className="text-zinc-300">
                    <span className="text-zinc-500">Date:</span>{" "}
                    {state.details.event.event_date}
                  </div>
                  <div className="text-zinc-300">
                    <span className="text-zinc-500">Ticket:</span>{" "}
                    <span className="font-mono">{state.details.ticket.code}</span>
                  </div>
                </div>

                <div className="flex gap-3">
                  <button
                    onClick={acceptEntry}
                    className="flex-1 rounded-lg bg-emerald-400 px-4 py-2 text-sm font-semibold text-emerald-950 hover:bg-emerald-300"
                  >
                    Accept Entry
                  </button>
                  <button
                    onClick={resumeScan}
                    className="flex-1 rounded-lg bg-zinc-200 px-4 py-2 text-sm font-semibold text-zinc-950 hover:bg-white"
                  >
                    Reject / Back
                  </button>
                </div>
              </div>
            ) : (
              <div className="mt-4">
                <button
                  onClick={resumeScan}
                  className="w-full rounded-lg bg-zinc-200 px-4 py-2 text-sm font-semibold text-zinc-950 hover:bg-white"
                >
                  Scan again
                </button>
              </div>
            )}
          </div>
        ) : (
          <div className="text-sm text-zinc-300">
            Point camera at the student QR. On success, verify the photo and tap{" "}
            <span className="font-semibold text-white">Accept Entry</span>.
          </div>
        )}
      </aside>
    </div>
  );
}
