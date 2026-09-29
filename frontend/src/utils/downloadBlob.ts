/** Saves an in-memory Blob (an authenticated download fetched through axios) as a file via a temporary link. */
export function saveBlob(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  // Revoke on the next tick so the browser has started the download.
  window.setTimeout(() => URL.revokeObjectURL(url), 1000)
}
