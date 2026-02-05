/**
 * 画像読み込みエラー（404）を検出してAPIに報告する
 */
(function () {
    const SELECTORS = '.talkroom_banner_img, .openchat-item-img'
    const REPORT_API_URL = '/api/report-image-error'
    const reportedUrls = new Set()

    async function reportImageError(imgUrl) {
        if (reportedUrls.has(imgUrl)) {
            return
        }
        reportedUrls.add(imgUrl)

        // 再度リクエストして本当に404か検証
        try {
            const verifyResponse = await fetch(imgUrl, { method: 'HEAD' })
            if (verifyResponse.ok) {
                console.log('[imageErrorReporter] False positive detected, image is accessible:', imgUrl)
                return
            }
        } catch (e) {
            // fetchエラーの場合は404として扱う
        }

        console.log('[imageErrorReporter] Reporting image error to API:', imgUrl)

        fetch(REPORT_API_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ url: imgUrl })
        }).catch(function (error) {
            console.error('[imageErrorReporter] Failed to report image error:', error)
        })
    }

    function handleImageError(event) {
        const img = event.target
        if (img.src) {
            reportImageError(img.src)
        }
    }

    function observeImages() {
        const images = document.querySelectorAll(SELECTORS)
        images.forEach(function (img) {
            if (img.complete) {
                // 既に読み込み完了している場合
                if (img.naturalWidth === 0 && img.naturalHeight === 0) {
                    // 画像が読み込めなかった（404など）
                    reportImageError(img.src)
                }
            } else {
                // まだ読み込み中の場合
                img.addEventListener('error', handleImageError, { once: true })
            }
        })
    }

    // DOM読み込み完了時に実行
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', observeImages)
    } else {
        observeImages()
    }
})()
