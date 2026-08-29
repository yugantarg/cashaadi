/* ==========================================================================
   CAShaadi UI — Profile Tools
   --------------------------------------------------------------------------
   Host handle for the completion meter (WPCode #11560). The meter itself is
   server-rendered markup; the only script it needs is the one-time GA4
   `profile_complete` dataLayer push, which PHP attaches inline to this handle
   via wp_add_inline_script() when a user first reaches 100%. Kept as a real
   file so there is a stable handle to enqueue and hang that inline script on.
   ========================================================================== */
