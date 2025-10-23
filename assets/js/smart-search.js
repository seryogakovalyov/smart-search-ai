jQuery(document).ready(function ($) {
  const searchQuery = new URLSearchParams(window.location.search).get("s");
  if (!searchQuery) return;

  console.log("🔍 Search query detected:", searchQuery);

  $(document).on("click", "a", function () {
    const $link = $(this);
    let postId = null;

    $link.parents().each(function () {
      const classes = ($(this).attr("class") || "").split(/\s+/);
      for (const cls of classes) {
        const match = cls.match(/^post-(\d+)$/);
        if (match) {
          postId = match[1];
          return false;
        }
      }
      if (postId) return false;
    });

    if (!postId) {
      return;
    }

    $.post(ssai_ajax.url, {
      action: "ssai_register_click",
      post_id: postId,
      query: searchQuery,
    });
  });
});
