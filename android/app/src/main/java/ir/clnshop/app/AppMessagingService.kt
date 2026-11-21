package ir.clnshop.app

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import java.net.HttpURLConnection
import java.net.URL

class AppMessagingService : FirebaseMessagingService() {

    override fun onNewToken(token: String) {
        Push.onToken(applicationContext, token)
    }

    // پیام‌های سرور فقط data دارند؛ بنابراین همیشه (حتی وقتی اپ بسته است) این متد اجرا می‌شود.
    override fun onMessageReceived(message: RemoteMessage) {
        val data = message.data
        val title = data["title"].orEmpty().ifBlank { getString(R.string.app_name) }
        val body = data["body"].orEmpty()
        val url = data["url"].orEmpty()
        val image = data["image"].orEmpty()
        showNotification(title, body, url, image)
    }

    private fun showNotification(title: String, body: String, url: String, imageUrl: String) {
        if (Build.VERSION.SDK_INT >= 33 &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS)
            != PackageManager.PERMISSION_GRANTED
        ) return

        ensureChannel()

        val intent = Intent(this, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP
            if (url.isNotBlank()) putExtra(MainActivity.EXTRA_URL, url)
        }
        val id = (System.currentTimeMillis() and 0x7FFFFFFF).toInt()
        val pending = PendingIntent.getActivity(
            this, id, intent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val builder = NotificationCompat.Builder(this, CHANNEL_ID)
            .setSmallIcon(R.drawable.ic_stat_notify)
            .setColor(ContextCompat.getColor(this, R.color.brand))
            .setContentTitle(title)
            .setContentText(body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setAutoCancel(true)
            .setContentIntent(pending)

        val bitmap = downloadImage(imageUrl)
        if (bitmap != null) {
            builder.setLargeIcon(bitmap)
            builder.setStyle(
                NotificationCompat.BigPictureStyle()
                    .bigPicture(bitmap)
                    .bigLargeIcon(null as Bitmap?)
                    .setSummaryText(body)
            )
        }

        NotificationManagerCompat.from(this).notify(id, builder.build())
    }

    private fun ensureChannel() {
        if (Build.VERSION.SDK_INT < 26) return
        val nm = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        if (nm.getNotificationChannel(CHANNEL_ID) == null) {
            nm.createNotificationChannel(
                NotificationChannel(
                    CHANNEL_ID,
                    getString(R.string.channel_name),
                    NotificationManager.IMPORTANCE_HIGH
                )
            )
        }
    }

    private fun downloadImage(imageUrl: String): Bitmap? {
        if (!imageUrl.startsWith("https://")) return null
        return try {
            val conn = URL(imageUrl).openConnection() as HttpURLConnection
            conn.connectTimeout = 5000
            conn.readTimeout = 5000
            conn.inputStream.use { BitmapFactory.decodeStream(it) }
        } catch (e: Exception) {
            null
        }
    }

    companion object {
        const val CHANNEL_ID = "clnshop_default"
    }
}
