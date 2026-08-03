export function useClipboard() {
    async function copyText(text) {
        if (window.isSecureContext && navigator.clipboard?.writeText) {
            try {
                await navigator.clipboard.writeText(text)
                return true
            } catch {
                // sigue al fallback
            }
        }

        try {
            const el = document.createElement('textarea')
            el.value = text
            el.style.position = 'fixed'
            el.style.left = '-9999px'
            el.style.opacity = '0'
            document.body.appendChild(el)
            el.focus()
            el.select()
            const ok = document.execCommand('copy')
            document.body.removeChild(el)
            if (ok) return true
        } catch {
            // sin soporte, se retorna false
        }

        return false
    }

    return { copyText }
}
