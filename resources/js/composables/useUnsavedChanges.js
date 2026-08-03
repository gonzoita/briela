import { ref, onBeforeUnmount } from 'vue'
import { router } from '@inertiajs/vue3'

export function useUnsavedChanges() {
    const hasChanges = ref(false)
    let originalData = null

    function setOriginal(data) {
        originalData = JSON.stringify(data)
        hasChanges.value = false
    }

    function checkChanges(currentData) {
        if (originalData === null) return
        hasChanges.value = JSON.stringify(currentData) !== originalData
    }

    function markClean() {
        hasChanges.value = false
    }

    const removeListener = router.on('before', (event) => {
        if (hasChanges.value) {
            const confirmed = window.confirm(
                '⚠️ Tienes cambios sin guardar.\n¿Seguro que quieres salir? Se perderán los cambios.'
            )
            if (!confirmed) {
                event.preventDefault()
                return false
            }
        }
    })

    function onBeforeUnloadHandler(e) {
        if (hasChanges.value) {
            e.preventDefault()
            e.returnValue = ''
        }
    }
    window.addEventListener('beforeunload', onBeforeUnloadHandler)

    onBeforeUnmount(() => {
        removeListener()
        window.removeEventListener('beforeunload', onBeforeUnloadHandler)
    })

    return { hasChanges, setOriginal, checkChanges, markClean }
}
