export type ApiOk<T> = { status: "ok"; message: string } & T;
export type ApiErr = { status: "error"; message: string; [k: string]: unknown };

const defaultBase = "/attendanceqr/api";
export const API_BASE =
  (import.meta.env.VITE_API_BASE as string | undefined) ?? defaultBase;

async function jsonFetch<T>(
  path: string,
  init?: RequestInit
): Promise<ApiOk<T> | ApiErr> {
  const res = await fetch(`${API_BASE}${path}`, {
    ...init,
    headers: {
      "Content-Type": "application/json",
      ...(init?.headers ?? {}),
    },
  });
  const data = (await res.json()) as ApiOk<T> | ApiErr;
  return data;
}

export async function issueQr(ticketId: number, studentId?: number) {
  const qs = new URLSearchParams({ ticket_id: String(ticketId) });
  if (studentId) qs.set("student_id", String(studentId));
  const res = await fetch(`${API_BASE}/issue_qr.php?${qs.toString()}`);
  return (await res.json()) as
    | ApiOk<{
        token: string;
        expires_at_epoch: number;
        expires_in_seconds: number;
        student: {
          id: number;
          full_name: string;
          enrollment: string;
          department: string;
          photo_url: string;
        };
        event: { id: number; name: string };
        ticket: { id: number; ticket_code: string };
      }>
    | ApiErr;
}

export async function validateQr(token: string) {
  return jsonFetch<{
    token: string;
    qr_token_id: number;
    student: {
      id: number;
      full_name: string;
      enrollment: string;
      department: string;
      photo_url: string;
    };
    event: { id: number; name: string; venue: string; event_date: string };
    ticket: { id: number; code: string; status: string };
  }>("/validate_qr.php", {
    method: "POST",
    body: JSON.stringify({ token }),
    credentials: "include",
  });
}

export async function confirmEntry(token: string) {
  return jsonFetch<{ ticket_id: number; used_at: string }>("/confirm_entry.php", {
    method: "POST",
    body: JSON.stringify({ token }),
    credentials: "include",
  });
}

