import { api } from './api';
import type { LoginResponse, MeResponse, SessionResponse } from '@/types/api';

export const authApi = {
  login: (email: string, password: string) => api.post<LoginResponse>('/auth/login', { email, password }, { skipMfaPrompt: true }),
  mfa: (code: string) => api.post<SessionResponse>('/auth/mfa', { code }, { skipMfaPrompt: true }),
  passkeyOptions: () => api.post<PublicKeyCredentialRequestOptionsJSONLike>('/auth/passkey/options', {}, { skipMfaPrompt: true }),
  passkeyVerify: (body: { id: string; clientDataJSON: string; authenticatorData: string; signature: string; userHandle?: string | null }) =>
    api.post<SessionResponse>('/auth/passkey/verify', body, { skipMfaPrompt: true }),
  logout: () => api.post<{ ok: true }>('/auth/logout', {}, { skipMfaPrompt: true }),
  me: () => api.get<MeResponse>('/auth/me'),
  stepUp: (code: string) => api.post<{ step_up_valid_until: string }>('/auth/step-up', { code }, { skipMfaPrompt: true }),
  totpSetup: () => api.post<{ secret: string; otpauth_uri: string }>('/auth/mfa/totp/setup', {}),
  totpConfirm: (code: string) => api.post<{ recovery_codes: string[] }>('/auth/mfa/totp/confirm', { code }, { skipMfaPrompt: true }),
  passkeyRegisterOptions: () => api.post<PublicKeyCredentialCreationOptionsJSONLike>('/auth/passkeys/register/options', {}),
  passkeyRegister: (body: { name: string; clientDataJSON: string; attestationObject: string; id?: string }) =>
    api.post<{ ok: true }>('/auth/passkeys/register', body),
  forgot: (email: string) => api.post<{ ok: true }>('/auth/password/forgot', { email }),
  reset: (token: string, password: string) => api.post<{ ok: true }>('/auth/password/reset', { token, password }),
  register: (email: string, password: string, name: string) => api.post<{ status: string }>('/auth/register', { email, password, name }),
  verifyEmail: (token: string) => api.post<{ ok: true }>('/auth/verify-email', { token }),
};

/** WebAuthn request options as JSON with base64url binary fields (may be wrapped in {publicKey}). */
export interface PublicKeyCredentialRequestOptionsJSONLike {
  publicKey?: PublicKeyCredentialRequestOptionsJSONLike;
  challenge?: string;
  rpId?: string;
  timeout?: number;
  userVerification?: UserVerificationRequirement;
  allowCredentials?: { id: string; type: 'public-key'; transports?: string[] }[];
}
export interface PublicKeyCredentialCreationOptionsJSONLike {
  publicKey?: PublicKeyCredentialCreationOptionsJSONLike;
  challenge?: string;
  rp?: { id?: string; name: string };
  user?: { id: string; name: string; displayName: string };
  pubKeyCredParams?: { type: 'public-key'; alg: number }[];
  timeout?: number;
  attestation?: AttestationConveyancePreference;
  authenticatorSelection?: AuthenticatorSelectionCriteria;
  excludeCredentials?: { id: string; type: 'public-key'; transports?: string[] }[];
}
