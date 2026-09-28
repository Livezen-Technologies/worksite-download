/** WebAuthn helpers: base64url <-> ArrayBuffer and the get/create ceremonies against the auth API. */
import { authApi, type PublicKeyCredentialCreationOptionsJSONLike, type PublicKeyCredentialRequestOptionsJSONLike } from './auth';
import type { SessionResponse } from '@/types/api';

export function base64urlToBuffer(value: string): ArrayBuffer {
  const pad = '='.repeat((4 - (value.length % 4)) % 4);
  const b64 = (value + pad).replace(/-/g, '+').replace(/_/g, '/');
  const bin = atob(b64);
  const buf = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
  return buf.buffer;
}

export function bufferToBase64url(buf: ArrayBuffer | ArrayBufferView): string {
  const bytes = buf instanceof ArrayBuffer ? new Uint8Array(buf) : new Uint8Array(buf.buffer, buf.byteOffset, buf.byteLength);
  let bin = '';
  for (const b of bytes) bin += String.fromCharCode(b);
  return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

export function passkeysSupported(): boolean {
  return typeof window !== 'undefined' && typeof window.PublicKeyCredential !== 'undefined' && !!navigator.credentials;
}

/** Sign in / step up with a passkey (pending login session or signed-in session). */
export async function passkeyAssertion(): Promise<SessionResponse> {
  const raw = await authApi.passkeyOptions();
  const o = (raw.publicKey ?? raw) as PublicKeyCredentialRequestOptionsJSONLike;
  const publicKey: PublicKeyCredentialRequestOptions = {
    challenge: base64urlToBuffer(o.challenge ?? ''),
    rpId: o.rpId,
    timeout: o.timeout,
    userVerification: o.userVerification ?? 'required',
    allowCredentials: o.allowCredentials?.map((c) => ({ id: base64urlToBuffer(c.id), type: 'public-key', transports: c.transports as AuthenticatorTransport[] | undefined })),
  };
  const cred = (await navigator.credentials.get({ publicKey })) as PublicKeyCredential | null;
  if (!cred) throw new Error('Passkey verification was cancelled');
  const resp = cred.response as AuthenticatorAssertionResponse;
  return authApi.passkeyVerify({
    id: cred.id,
    clientDataJSON: bufferToBase64url(resp.clientDataJSON),
    authenticatorData: bufferToBase64url(resp.authenticatorData),
    signature: bufferToBase64url(resp.signature),
    userHandle: resp.userHandle ? bufferToBase64url(resp.userHandle) : null,
  });
}

/** Register a new passkey for the signed-in user. */
export async function registerPasskey(name: string): Promise<void> {
  const raw = await authApi.passkeyRegisterOptions();
  const o = (raw.publicKey ?? raw) as PublicKeyCredentialCreationOptionsJSONLike;
  const publicKey: PublicKeyCredentialCreationOptions = {
    challenge: base64urlToBuffer(o.challenge ?? ''),
    rp: o.rp ?? { name: 'WorkSuite Update Center' },
    user: { id: base64urlToBuffer(o.user?.id ?? ''), name: o.user?.name ?? '', displayName: o.user?.displayName ?? '' },
    pubKeyCredParams: o.pubKeyCredParams ?? [
      { type: 'public-key', alg: -7 },
      { type: 'public-key', alg: -257 },
    ],
    timeout: o.timeout,
    attestation: o.attestation ?? 'none',
    authenticatorSelection: o.authenticatorSelection ?? { residentKey: 'preferred', userVerification: 'required' },
    excludeCredentials: o.excludeCredentials?.map((c) => ({ id: base64urlToBuffer(c.id), type: 'public-key' })),
  };
  const cred = (await navigator.credentials.create({ publicKey })) as PublicKeyCredential | null;
  if (!cred) throw new Error('Passkey registration was cancelled');
  const resp = cred.response as AuthenticatorAttestationResponse;
  await authApi.passkeyRegister({
    name,
    id: cred.id,
    clientDataJSON: bufferToBase64url(resp.clientDataJSON),
    attestationObject: bufferToBase64url(resp.attestationObject),
  });
}
