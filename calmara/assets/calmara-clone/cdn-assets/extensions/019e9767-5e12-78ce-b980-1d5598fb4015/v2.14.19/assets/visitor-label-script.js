(() => {
    console.log('[ABConvert] Running customer label script')
    const firstVisitTime = window.localStorage.getItem('abconvert-first-visit-time')
    if (!firstVisitTime) {
        const now = new Date().valueOf()
        window.localStorage.setItem('abconvert-first-visit-time', now)
        window.localStorage.setItem('abconvert.visitor.first_seen', now.toString())
    } else if (!window.localStorage.getItem('abconvert.visitor.first_seen')) {
        window.localStorage.setItem('abconvert.visitor.first_seen', firstVisitTime)
    }
})()
