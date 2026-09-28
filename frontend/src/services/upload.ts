/**
 * Chunked, resumable package uploads over tus 1.0 (A3 / W2).
 *
 * Flow: POST /admin/releases/{id}/packages → { package, upload: { id, tus_endpoint, chunk_size } } then
 * tus POST (Upload-Metadata: upload_id <b64>) / HEAD / PATCH. The server quarantines the file and queues
 * processing after the final PATCH; the UI then polls GET …/packages/{pkg}.
 *
 * Browser-refresh recovery: tus-js-client stores the upload URL under a fingerprint in localStorage;
 * we additionally remember which package slot / upload id the fingerprint belongs to, so re-selecting the
 * same file after a reload resumes from the server offset instead of creating a new revision.
 */
import { Upload, type HttpStack } from 'tus-js-client';

type PreviousUpload = Awaited<ReturnType<Upload['findPreviousUploads']>>[number];
import { getCsrfToken } from './api';
import type { Arch, Edition, PackageType, Platform } from '@/types/api';

let customHttpStack: HttpStack | undefined;
/** Mock mode installs an in-memory tus server here. */
export function setTusHttpStack(stack: HttpStack | undefined): void {
  customHttpStack = stack;
}

export interface SlotKey {
  releaseId: string;
  platform: Platform;
  architecture: Arch;
  packageType: PackageType;
  edition?: Edition | null;
}

export interface PendingUpload extends SlotKey {
  fingerprint: string;
  packageId: string;
  uploadId: string;
  tusEndpoint: string;
  chunkSize: number;
  fileName: string;
  size: number;
  lastModified: number;
  startedAt: string;
}

const PENDING_KEY = 'uc.uploads.pending';

function safeStorage(): Storage | null {
  try {
    return typeof localStorage !== 'undefined' ? localStorage : null;
  } catch {
    return null;
  }
}

export function uploadFingerprint(slot: SlotKey, file: { name: string; size: number; lastModified: number }): string {
  return ['uc-tus', slot.releaseId, slot.platform, slot.architecture, slot.packageType, slot.edition ?? '-', file.name, file.size, file.lastModified].join('|');
}

export function listPendingUploads(releaseId?: string): PendingUpload[] {
  const s = safeStorage();
  if (!s) return [];
  try {
    const all = JSON.parse(s.getItem(PENDING_KEY) || '[]') as PendingUpload[];
    return releaseId ? all.filter((p) => p.releaseId === releaseId) : all;
  } catch {
    return [];
  }
}

export function savePendingUpload(p: PendingUpload): void {
  const s = safeStorage();
  if (!s) return;
  const all = listPendingUploads().filter((x) => x.fingerprint !== p.fingerprint);
  all.push(p);
  try {
    s.setItem(PENDING_KEY, JSON.stringify(all));
  } catch {
    /* storage full / blocked — resume just won't survive a reload */
  }
}

export function clearPendingUpload(fingerprint: string): void {
  const s = safeStorage();
  if (!s) return;
  try {
    s.setItem(PENDING_KEY, JSON.stringify(listPendingUploads().filter((x) => x.fingerprint !== fingerprint)));
  } catch {
    /* ignore */
  }
}

export function findPendingUpload(fingerprint: string): PendingUpload | undefined {
  return listPendingUploads().find((p) => p.fingerprint === fingerprint);
}

export interface UploadProgress {
  bytesUploaded: number;
  bytesTotal: number;
  chunksDone: number;
  chunksTotal: number;
  retries: number;
  resumedFromOffset: number | null;
}

export interface TusController {
  start(): void;
  pause(): void;
  abort(): Promise<void>;
  readonly fingerprint: string;
}

export interface TusUploadOptions {
  file: File | Blob & { name: string; lastModified: number };
  pending: PendingUpload;
  onProgress: (p: UploadProgress) => void;
  onSuccess: () => void;
  onError: (err: Error, willRetry: boolean) => void;
}

export function createTusUpload(opts: TusUploadOptions): TusController {
  const { file, pending } = opts;
  const chunksTotal = Math.max(1, Math.ceil(pending.size / pending.chunkSize));
  let retries = 0;
  let resumedFromOffset: number | null = null;
  let lastUploaded = 0;
  let started = false;

  const upload = new Upload(file as File, {
    endpoint: pending.tusEndpoint,
    chunkSize: pending.chunkSize,
    retryDelays: [0, 1000, 3000, 5000, 10000, 20000],
    metadata: { upload_id: pending.uploadId },
    storeFingerprintForResuming: true,
    removeFingerprintOnSuccess: true,
    fingerprint: async () => pending.fingerprint,
    // Only override when mock mode installed a stack: an explicit `undefined` would replace
    // tus-js-client's default XHR stack (options are merged with a spread).
    ...(customHttpStack ? { httpStack: customHttpStack } : {}),
    onBeforeRequest(req) {
      const method = req.getMethod();
      if (method !== 'GET' && method !== 'HEAD') {
        const csrf = getCsrfToken();
        if (csrf) req.setHeader('X-CSRF-Token', csrf);
      }
      const xhr = req.getUnderlyingObject() as { withCredentials?: boolean } | undefined;
      if (xhr && typeof xhr === 'object' && 'withCredentials' in xhr) xhr.withCredentials = true;
    },
    onShouldRetry(_err, _retryAttempt, options) {
      retries++;
      opts.onError(new Error('Network interrupted — resuming…'), true);
      return options.retryDelays != null;
    },
    onProgress(bytesUploaded, bytesTotal) {
      lastUploaded = bytesUploaded;
      opts.onProgress({
        bytesUploaded,
        bytesTotal,
        chunksDone: Math.min(chunksTotal, Math.floor(bytesUploaded / pending.chunkSize)),
        chunksTotal,
        retries,
        resumedFromOffset,
      });
    },
    onChunkComplete(_chunk, bytesAccepted, bytesTotal) {
      lastUploaded = bytesAccepted;
      opts.onProgress({
        bytesUploaded: bytesAccepted,
        bytesTotal,
        chunksDone: Math.min(chunksTotal, Math.ceil(bytesAccepted / pending.chunkSize)),
        chunksTotal,
        retries,
        resumedFromOffset,
      });
    },
    onSuccess() {
      clearPendingUpload(pending.fingerprint);
      opts.onSuccess();
    },
    onError(err) {
      opts.onError(err, false);
    },
  });

  return {
    fingerprint: pending.fingerprint,
    start() {
      if (started) {
        // resume after pause/error: tus re-HEADs the stored URL and continues from the server offset
        if (lastUploaded > 0) resumedFromOffset = lastUploaded;
        upload.start();
        return;
      }
      started = true;
      upload
        .findPreviousUploads()
        .then((previous: PreviousUpload[]) => {
          if (previous.length > 0) {
            resumedFromOffset = 0; // actual offset comes from HEAD; flagged so the UI can say "resumed"
            upload.resumeFromPreviousUpload(previous[0]);
          }
          upload.start();
        })
        .catch(() => upload.start());
    },
    pause() {
      void upload.abort(false);
    },
    abort() {
      clearPendingUpload(pending.fingerprint);
      return upload.abort(true).catch(() => undefined);
    },
  };
}
