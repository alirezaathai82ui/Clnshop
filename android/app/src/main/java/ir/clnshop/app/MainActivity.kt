package ir.clnshop.app

import android.Manifest
import android.annotation.SuppressLint
import android.app.DownloadManager
import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Bitmap
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Environment
import android.view.View
import android.webkit.CookieManager
import android.webkit.JavascriptInterface
import android.webkit.URLUtil
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Button
import android.widget.ProgressBar
import android.widget.Toast
import androidx.activity.OnBackPressedCallback
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.core.splashscreen.SplashScreen.Companion.installSplashScreen
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout

class MainActivity : AppCompatActivity() {

    private lateinit var webView: WebView
    private lateinit var swipe: SwipeRefreshLayout
    private lateinit var progress: ProgressBar
    private lateinit var offlineView: View

    private var fileCallback: ValueCallback<Array<Uri>>? = null

    private val fileLauncher =
        registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
            val uris = WebChromeClient.FileChooserParams.parseResult(result.resultCode, result.data)
            fileCallback?.onReceiveValue(uris)
            fileCallback = null
        }

    private val notificationPermission =
        registerForActivityResult(ActivityResultContracts.RequestPermission()) { /* نتیجه مهم نیست */ }

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        installSplashScreen()
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        webView = findViewById(R.id.webView)
        swipe = findViewById(R.id.swipe)
        progress = findViewById(R.id.progress)
        offlineView = findViewById(R.id.offline)

        findViewById<Button>(R.id.retry).setOnClickListener {
            offlineView.visibility = View.GONE
            swipe.visibility = View.VISIBLE
            if (webView.url.isNullOrBlank()) webView.loadUrl(BuildConfig.BASE_URL) else webView.reload()
        }

        swipe.setColorSchemeResources(R.color.brand)
        swipe.setOnRefreshListener { webView.reload() }
        // فقط وقتی صفحه در بالاترین نقطه است کشیدن برای تازه‌سازی فعال باشد
        swipe.setOnChildScrollUpCallback { _, _ -> webView.scrollY > 0 }

        setupWebView()
        setupBackNavigation()
        requestNotificationPermission()
        Push.init(this)
        applyTokenCookie()

        val start = resolveInternalUrl(intent?.getStringExtra(EXTRA_URL))
            ?: resolveInternalUrl(intent?.dataString)
            ?: BuildConfig.BASE_URL
        webView.loadUrl(start)
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        val url = resolveInternalUrl(intent.getStringExtra(EXTRA_URL))
            ?: resolveInternalUrl(intent.dataString)
        if (url != null) webView.loadUrl(url)
    }

    override fun onPause() {
        super.onPause()
        CookieManager.getInstance().flush()
        webView.onPause()
    }

    override fun onResume() {
        super.onResume()
        webView.onResume()
        applyTokenCookie()
    }

    override fun onDestroy() {
        fileCallback?.onReceiveValue(null)
        fileCallback = null
        webView.destroy()
        super.onDestroy()
    }

    // ---------------------------------------------------------------- WebView

    @SuppressLint("SetJavaScriptEnabled")
    private fun setupWebView() {
        val s = webView.settings
        s.javaScriptEnabled = true
        s.domStorageEnabled = true
        s.databaseEnabled = true
        s.allowFileAccess = false
        s.allowContentAccess = false
        s.mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW
        s.mediaPlaybackRequiresUserGesture = true
        s.loadWithOverviewMode = true
        s.useWideViewPort = true
        s.setSupportZoom(false)
        s.builtInZoomControls = false
        s.userAgentString = s.userAgentString + " ClnShopApp/" + BuildConfig.VERSION_NAME

        val cookies = CookieManager.getInstance()
        cookies.setAcceptCookie(true)
        // درگاه‌های پرداخت به کوکی شخص ثالث نیاز دارند
        cookies.setAcceptThirdPartyCookies(webView, true)

        webView.addJavascriptInterface(JsBridge(), "ClnApp")

        webView.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
                return handleUrl(request.url)
            }

            override fun onPageStarted(view: WebView, url: String?, favicon: Bitmap?) {
                progress.visibility = View.VISIBLE
            }

            override fun onPageFinished(view: WebView, url: String?) {
                progress.visibility = View.GONE
                swipe.isRefreshing = false
                CookieManager.getInstance().flush()
                applyTokenCookie()
                syncDevice(view, url)
            }

            override fun onReceivedError(
                view: WebView,
                request: WebResourceRequest,
                error: WebResourceError
            ) {
                if (request.isForMainFrame) {
                    swipe.isRefreshing = false
                    progress.visibility = View.GONE
                    swipe.visibility = View.GONE
                    offlineView.visibility = View.VISIBLE
                }
            }
        }

        webView.webChromeClient = object : WebChromeClient() {
            override fun onProgressChanged(view: WebView, newProgress: Int) {
                progress.progress = newProgress
            }

            override fun onShowFileChooser(
                webView: WebView,
                filePathCallback: ValueCallback<Array<Uri>>,
                fileChooserParams: FileChooserParams
            ): Boolean {
                fileCallback?.onReceiveValue(null)
                fileCallback = filePathCallback
                return try {
                    fileLauncher.launch(fileChooserParams.createIntent())
                    true
                } catch (e: ActivityNotFoundException) {
                    fileCallback = null
                    false
                }
            }
        }

        webView.setDownloadListener { url, userAgent, contentDisposition, mimeType, _ ->
            try {
                val req = DownloadManager.Request(Uri.parse(url))
                    .setMimeType(mimeType)
                    .addRequestHeader("Cookie", CookieManager.getInstance().getCookie(url) ?: "")
                    .addRequestHeader("User-Agent", userAgent)
                    .setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
                    .setDestinationInExternalPublicDir(
                        Environment.DIRECTORY_DOWNLOADS,
                        URLUtil.guessFileName(url, contentDisposition, mimeType)
                    )
                (getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager).enqueue(req)
                Toast.makeText(this, R.string.download_started, Toast.LENGTH_SHORT).show()
            } catch (e: Exception) {
                openExternal(Uri.parse(url))
            }
        }
    }

    /**
     * بعد از هر صفحه، توکن دستگاه را از داخل خود WebView (با کوکی ورود کاربر) به سایت می‌فرستد
     * تا دستگاه به حساب کاربر وصل شود؛ با خروج از حساب هم ارتباط قطع می‌شود.
     */
    private fun syncDevice(view: WebView, url: String?) {
        val token = Push.currentToken(this)
        if (token.isBlank()) return
        val host = try { Uri.parse(url ?: "").host } catch (e: Exception) { null }
        if (!isInternalHost(host)) return
        val js = "(function(){try{fetch('/wp-json/clnshop-app/v1/register',{method:'POST'," +
            "credentials:'same-origin',headers:{'Content-Type':'application/json','X-ClnShop-App':'1'}," +
            "body:JSON.stringify({token:'" + token + "',app_version:'" + BuildConfig.VERSION_NAME + "'})});}catch(e){}})();"
        view.evaluateJavascript(js, null)
    }

    /**
     * توکن دستگاه را در کوکی سایت می‌گذارد. سایت در هر درخواست کاربر واردشده، این کوکی را می‌خواند
     * و دستگاه را به حساب او وصل می‌کند (با خروج از حساب هم قطع می‌شود).
     */
    private fun applyTokenCookie() {
        val token = Push.currentToken(this)
        if (token.isBlank()) return
        try {
            val cm = CookieManager.getInstance()
            cm.setCookie(
                "https://" + BuildConfig.SITE_HOST,
                "clnapp_token=" + token + "; Max-Age=31536000; Path=/; Domain=" + BuildConfig.SITE_HOST + "; Secure; SameSite=Lax"
            )
            cm.flush()
        } catch (e: Exception) {
            // بدون کوکی هم اپ کار می‌کند؛ فقط اتصال به حساب انجام نمی‌شود
        }
    }

    private fun setupBackNavigation() {
        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                if (webView.canGoBack()) {
                    webView.goBack()
                } else {
                    isEnabled = false
                    onBackPressedDispatcher.onBackPressed()
                }
            }
        })
    }

    private fun requestNotificationPermission() {
        if (Build.VERSION.SDK_INT >= 33 &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS)
            != PackageManager.PERMISSION_GRANTED
        ) {
            notificationPermission.launch(Manifest.permission.POST_NOTIFICATIONS)
        }
    }

    // ---------------------------------------------------------------- لینک‌ها

    private fun isInternalHost(host: String?): Boolean {
        if (host == null) return false
        return host == BuildConfig.SITE_HOST || host.endsWith("." + BuildConfig.SITE_HOST)
    }

    /** فقط لینک‌های https همین سایت پذیرفته می‌شوند (برای نوتیفیکیشن و لینک خارجی). */
    private fun resolveInternalUrl(raw: String?): String? {
        if (raw.isNullOrBlank()) return null
        val uri = try { Uri.parse(raw) } catch (e: Exception) { return null }
        return if (uri.scheme == "https" && isInternalHost(uri.host)) raw else null
    }

    private fun handleUrl(uri: Uri): Boolean {
        val scheme = uri.scheme ?: return true
        if (scheme == "http" || scheme == "https") {
            val host = uri.host.orEmpty()
            if (isInternalHost(host)) return false
            // شبکه‌های اجتماعی و پیام‌رسان‌ها بیرون از اپ باز شوند
            if (EXTERNAL_HOSTS.any { host == it || host.endsWith(".$it") }) {
                openExternal(uri)
                return true
            }
            // بقیه (مثل درگاه پرداخت) داخل همین WebView باز می‌شوند
            return false
        }
        openExternal(uri)
        return true
    }

    private fun openExternal(uri: Uri) {
        try {
            startActivity(Intent(Intent.ACTION_VIEW, uri))
        } catch (e: ActivityNotFoundException) {
            Toast.makeText(this, R.string.no_app_found, Toast.LENGTH_SHORT).show()
        }
    }

    // ---------------------------------------------------------------- پل جاوااسکریپت

    inner class JsBridge {
        @JavascriptInterface
        fun getToken(): String = Push.currentToken(this@MainActivity)

        @JavascriptInterface
        fun getVersion(): String = BuildConfig.VERSION_NAME

        @JavascriptInterface
        fun share(text: String) {
            runOnUiThread {
                val send = Intent(Intent.ACTION_SEND).apply {
                    type = "text/plain"
                    putExtra(Intent.EXTRA_TEXT, text)
                }
                startActivity(Intent.createChooser(send, null))
            }
        }
    }

    companion object {
        const val EXTRA_URL = "clnshop_url"

        private val EXTERNAL_HOSTS = listOf(
            "instagram.com", "t.me", "telegram.me", "wa.me", "whatsapp.com",
            "youtube.com", "youtu.be", "aparat.com", "eitaa.com", "rubika.ir"
        )
    }
}
