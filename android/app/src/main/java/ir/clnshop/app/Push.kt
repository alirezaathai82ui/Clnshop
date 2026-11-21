package ir.clnshop.app

import android.content.Context
import android.util.Log
import com.google.firebase.messaging.FirebaseMessaging
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import kotlin.concurrent.thread

/** ثبت دستگاه برای نوتیفیکیشن: تاپیک عمومی + ارسال توکن به سایت */
object Push {
    private const val PREFS = "clnshop_prefs"
    private const val KEY_TOKEN = "fcm_token"
    private const val TAG = "ClnPush"

    fun init(context: Context) {
        val app = context.applicationContext
        val fm = FirebaseMessaging.getInstance()
        fm.subscribeToTopic(BuildConfig.TOPIC_ALL)
        fm.token.addOnSuccessListener { token ->
            onToken(app, token)
        }.addOnFailureListener {
            Log.w(TAG, "token fetch failed", it)
        }
    }

    fun onToken(context: Context, token: String) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit().putString(KEY_TOKEN, token).apply()
        register(token)
    }

    fun currentToken(context: Context): String =
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString(KEY_TOKEN, "") ?: ""

    /** ثبت ناشناس؛ اتصال به حساب کاربر هنگام باز شدن صفحات سایت داخل اپ انجام می‌شود. */
    private fun register(token: String) {
        thread(name = "clnshop-register") {
            try {
                val url = URL(BuildConfig.BASE_URL + "wp-json/clnshop-app/v1/register")
                val conn = url.openConnection() as HttpURLConnection
                conn.requestMethod = "POST"
                conn.connectTimeout = 10000
                conn.readTimeout = 10000
                conn.doOutput = true
                conn.setRequestProperty("Content-Type", "application/json; charset=utf-8")
                val body = JSONObject()
                    .put("token", token)
                    .put("app_version", BuildConfig.VERSION_NAME)
                    .toString()
                conn.outputStream.use { it.write(body.toByteArray(Charsets.UTF_8)) }
                conn.responseCode
                conn.disconnect()
            } catch (e: Exception) {
                Log.w(TAG, "register failed", e)
            }
        }
    }
}
