import { useEffect, useMemo, useState } from "react";
import { useSearchParams } from "react-router-dom";
import QRCode from "qrcode.react";
import { issueQr } from "../lib/api";

type PassState =
  | { kind: "idle" }
  | { kind: "loading" }
  | { kind: "ready"; token: string; expiresAtEpoch: number; name: string; event: string }
  | { kind: "error"; message: string };

export default function StudentPass() {
  const [params] = useSearchParams();
  const ticketId = Number(params.get("ticket_id") ?? "0");
  const studentId = Number(params.get("student_id") ?? "0");

  const [state, setState] = useState<PassState>({ kind: "idle" });
  const [nowEpoch, setNowEpoch] = useState(() => Math.floor(Date.now() / 1000));

  const expiresIn = useMemo(() => {
    if (state.kind !== "ready") return 0;
    return Math.max(0, state.expiresAtEpoch - nowEpoch);
  }, [state, nowEpoch]);

  useEffect(() => {
    const t = window.setInterval(() => setNowEpoch(Math.floor(Date.now() / 1000)), 250);
    return () => window.clearInterval(t);
  }, []);

  async function refresh() {
    if (!Number.isFinite(ticketId) || ticketId <= 0) {
      setState({ kind: "error", message: "Missing ticket_id in URL." });
      return;
    }
    setState((s) => (s.kind === "ready" ? s : { kind: "loading" }));
    const res = await issueQr(ticketId, studentId > 0 ? studentId : undefined);
    if (res.status !== "ok") {
      setState({ kind: "error", message: res.message });
      return;
    }
    setState({
      kind: "ready",
      token: res.token,
      expiresAtEpoch: res.expires_at_epoch,
      name: res.student.full_name,
      event: res.event.name,
    });
  }

  useEffect(() => {
    refresh();
    const t = window.setInterval(() => refresh(), 30_000);
    return () => window.clearInterval(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ticketId, studentId]);

  const expired = state.kind === "ready" && expiresIn <= 0;

  return (
    <div className="grid gap-6 md:grid-cols-2">
      <section className="rounded-2xl border border-zinc-800 bg-zinc-900/40 p-5">
        <h1 className="text-xl font-semibold">Student Event Pass</h1>
        <p className="mt-1 text-sm text-zinc-300">
          QR refreshes every 30 seconds. Old QR becomes invalid immediately.
        </p>

        <div className="mt-4 rounded-xl border border-zinc-800 bg-zinc-950 p-4">
          {state.kind === "error" ? (
            <div className="text-red-300">{state.message}</div>
          ) : state.kind === "ready" ? (
            <div className="flex flex-col items-center gap-3">
              {expired ? (
                <div className="rounded-lg border border-red-700 bg-red-950 px-4 py-3 text-red-200">
                  QR EXPIRED
                </div>
              ) : (
                <div className="rounded-xl bg-white p-3">
                  <QRCode value={state.token} size={240} includeMargin />
                </div>
              )}
              <div className="text-sm text-zinc-300">
                Expires in{" "}
                <span className="font-mono text-white">{expiresIn}s</span>
              </div>
              <button
                onClick={refresh}
                className="rounded-lg bg-zinc-100 px-4 py-2 text-sm font-semibold text-zinc-950 hover:bg-white"
              >
                Refresh now
              </button>
            </div>
          ) : (
            <div className="text-zinc-300">Loading…</div>
          )}
        </div>
      </section>

      <section className="rounded-2xl border border-zinc-800 bg-zinc-900/40 p-5">
        <h2 className="text-lg font-semibold">Details</h2>
        <div className="mt-3 space-y-2 text-sm text-zinc-200">
          <div>
            <span className="text-zinc-400">Ticket ID:</span>{" "}
            <span className="font-mono">{ticketId || "-"}</span>
          </div>
          <div>
            <span className="text-zinc-400">Student:</span>{" "}
            {state.kind === "ready" ? state.name : "-"}
          </div>
          <div>
            <span className="text-zinc-400">Event:</span>{" "}
            {state.kind === "ready" ? state.event : "-"}
          </div>
          <div className="text-xs text-zinc-400">
            Tip: open this page on the student phone. Don’t use screenshots—
            scanner rejects old tokens.
          </div>
        </div>
      </section>
    </div>
  );
}

