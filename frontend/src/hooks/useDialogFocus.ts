import { useEffect, type RefObject } from 'react'

/**
 * Keyboard/screen-reader basics for a modal surface: when it opens, focus
 * moves into it (unless something inside - e.g. an autofocused field -
 * already has focus), and when it closes, focus returns to whatever opened
 * it. Without this, Tab keeps walking the page behind the scrim.
 */
export function useDialogFocus(open: boolean, panelRef: RefObject<HTMLElement | null>) {
  useEffect(() => {
    if (!open) return
    const previous = document.activeElement instanceof HTMLElement ? document.activeElement : null
    const panel = panelRef.current
    if (panel && !panel.contains(document.activeElement)) panel.focus({ preventScroll: true })
    return () => {
      if (previous && document.contains(previous)) previous.focus({ preventScroll: true })
    }
  }, [open, panelRef])
}
