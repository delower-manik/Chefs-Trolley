import { afterEach, describe, expect, it, vi } from "vitest";
import { apiRequest, ApiError } from "@/lib/api/client";

function mockFetchOnce(status: number, body: unknown) {
  vi.stubGlobal(
    "fetch",
    vi.fn().mockResolvedValue({
      ok: status >= 200 && status < 300,
      status,
      json: async () => body,
    })
  );
}

describe("apiRequest", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("returns parsed JSON data on success", async () => {
    mockFetchOnce(200, { data: { id: "1", name: "Test" } });

    const result = await apiRequest<{ data: { id: string; name: string } }>("/ping");

    expect(result.data.name).toBe("Test");
  });

  it("throws an ApiError with the server's code and message on failure", async () => {
    mockFetchOnce(422, {
      code: "validation_error",
      message: "The given data was invalid.",
      errors: { name: ["The name field is required."] },
    });

    await expect(apiRequest("/categories", { method: "POST", body: {} })).rejects.toMatchObject({
      status: 422,
      code: "validation_error",
      fieldErrors: { name: ["The name field is required."] },
    });
  });

  it("falls back to a generic message when the error body isn't JSON", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue({
        ok: false,
        status: 504,
        json: async () => {
          throw new Error("not json");
        },
      })
    );

    try {
      await apiRequest("/slow-endpoint");
      expect.unreachable("apiRequest should have thrown");
    } catch (err) {
      expect(err).toBeInstanceOf(ApiError);
      expect((err as ApiError).message).toMatch(/went wrong/i);
    }
  });

  it("returns undefined for a 204 No Content response", async () => {
    mockFetchOnce(204, null);

    const result = await apiRequest("/orders/1");

    expect(result).toBeUndefined();
  });

  it("sends FormData as-is without a Content-Type header or JSON stringification", async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      status: 201,
      json: async () => ({ data: { id: "1" } }),
    });
    vi.stubGlobal("fetch", fetchMock);

    const form = new FormData();
    form.append("file", new Blob(["fake-image-bytes"]), "photo.jpg");

    await apiRequest("/media", { method: "POST", body: form });

    const call = fetchMock.mock.calls[0] as [string, RequestInit];
    const init = call[1];
    expect(init.body).toBe(form);
    expect((init.headers as Record<string, string>)["Content-Type"]).toBeUndefined();
  });

  it("returns the response body as a Blob when parseAs is blob", async () => {
    const csv = new Blob(["Date,Orders\n"], { type: "text/csv" });
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue({ ok: true, status: 200, blob: async () => csv })
    );

    const result = await apiRequest<Blob>("/reports/sales/export", { parseAs: "blob" });

    expect(result).toBe(csv);
  });

  it("still throws an ApiError (parsed as JSON) when a blob request fails", async () => {
    mockFetchOnce(403, { code: "forbidden", message: "Not allowed." });

    await expect(apiRequest("/reports/sales/export", { parseAs: "blob" })).rejects.toMatchObject({
      status: 403,
      code: "forbidden",
    });
  });
});
