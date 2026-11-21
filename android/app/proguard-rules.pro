# رابط جاوااسکریپت باید نام متدها را حفظ کند
-keepclassmembers class ir.clnshop.app.MainActivity$JsBridge {
    @android.webkit.JavascriptInterface <methods>;
}
-keepattributes JavascriptInterface
