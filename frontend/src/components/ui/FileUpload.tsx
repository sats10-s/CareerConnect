import { useRef, useState } from 'react'
import { UploadCloud, FileText, X } from 'lucide-react'

interface FileUploadProps {
  accept?: string
  helperText?: string
  selectedFile?: File | null
  onFileSelected: (file: File | null) => void
  disabled?: boolean
  error?: string
}

/**
 * New shared primitive - named in the ground rules' component list but
 * did not exist in this codebase (the pre-existing Resume upload in
 * ProfileSection/ResumeSection rolled its own inline <input type=file>
 * + drag target rather than a shared component). Built while wiring
 * Internship Document upload/resubmit on the My Internship page, which
 * needed the same "pick or drop a file, show what's picked, allow
 * clearing" interaction. A click target rather than a full drag-and-
 * drop zone, to keep the surface small - the accept/size constraints
 * themselves are still enforced server-side regardless of what this
 * component allows through.
 */
export function FileUpload({ accept, helperText, selectedFile, onFileSelected, disabled, error }: FileUploadProps) {
  const inputRef = useRef<HTMLInputElement>(null)
  const [isDragOver, setIsDragOver] = useState(false)

  function handleFiles(files: FileList | null) {
    const file = files?.[0]
    if (file) onFileSelected(file)
  }

  return (
    <div>
      <input
        ref={inputRef}
        type="file"
        accept={accept}
        className="hidden"
        disabled={disabled}
        onChange={(event) => {
          handleFiles(event.target.files)
          event.target.value = ''
        }}
      />

      {selectedFile ? (
        <div className="flex items-center justify-between gap-3 rounded-lg border border-border bg-sidebar px-4 py-3">
          <div className="flex min-w-0 items-center gap-3">
            <FileText className="h-5 w-5 shrink-0 text-indigo" aria-hidden="true" />
            <p className="truncate text-sm font-medium text-ink">{selectedFile.name}</p>
          </div>
          {!disabled && (
            <button
              type="button"
              onClick={() => onFileSelected(null)}
              aria-label="Remove selected file"
              className="shrink-0 rounded-lg p-1 text-ink-soft hover:bg-hairline hover:text-ink"
            >
              <X className="h-4 w-4" />
            </button>
          )}
        </div>
      ) : (
        <button
          type="button"
          disabled={disabled}
          onClick={() => inputRef.current?.click()}
          onDragOver={(event) => {
            event.preventDefault()
            setIsDragOver(true)
          }}
          onDragLeave={() => setIsDragOver(false)}
          onDrop={(event) => {
            event.preventDefault()
            setIsDragOver(false)
            handleFiles(event.dataTransfer.files)
          }}
          className={`flex w-full flex-col items-center gap-2 rounded-lg border-2 border-dashed px-4 py-6 text-center transition-colors ${
            isDragOver ? 'border-primary bg-primary-tint' : 'border-hairline hover:border-primary'
          } ${error ? 'border-error' : ''}`}
        >
          <UploadCloud className="h-5 w-5 text-ink-faint" aria-hidden="true" />
          <span className="text-sm font-medium text-ink">Click to upload or drag and drop</span>
          {helperText && <span className="text-xs text-ink-faint">{helperText}</span>}
        </button>
      )}
      {error && <p className="mt-1 text-xs text-error">{error}</p>}
    </div>
  )
}
