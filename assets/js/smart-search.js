jQuery(function ($) {
  var ajaxData = window.ssai_ajax || {};
  var ajaxUrl = ajaxData.url;
  var nonce = ajaxData.nonce;
  var searchQuery = ajaxData.query || "";

  if (!ajaxUrl || !nonce) {
    return;
  }

  function findPostId($element) {
    var postId = null;

    $element.parents().each(function () {
      var classes = ($(this).attr("class") || "").split(/\s+/);
      for (var i = 0; i < classes.length; i++) {
        var cls = classes[i];
        var match = cls.match(/^post-(\d+)$/);
        if (match) {
          postId = match[1];
          return false;
        }
      }
      if (postId) {
        return false;
      }
    });

    return postId;
  }

  if (searchQuery) {
    $(document).on("click", "a", function () {
      var postId = findPostId($(this));
      if (!postId) {
        return;
      }

      $.post(ajaxUrl, {
        action: "ssai_register_click",
        post_id: postId,
        query: searchQuery,
        nonce: nonce,
      });
    });
  }

  var $searchInput = $("input[name='s']")
    .filter(function () {
      return !$(this).closest("#wpadminbar").length;
    })
    .first();

  if (!$searchInput.length) return;

  $searchInput.attr("autocomplete", "off");

  $searchInput.parent().addClass("ssai-autocomplete-wrapper");
  var $container = $searchInput.parent();

  var $list = $("<ul class='ssai-suggestions' style='display:none;'></ul>");
  $container.append($list);

  var currentIndex = -1;
  var currentItems = [];
  var requestId = 0;

  function hideSuggestions() {
    $list.hide();
    currentIndex = -1;
    currentItems = [];
  }

  function selectItem(index) {
    if (!currentItems.length) {
      return;
    }

    currentIndex = (index + currentItems.length) % currentItems.length;
    $list.children().removeClass("is-active");
    $list.children().eq(currentIndex).addClass("is-active");
  }

  function chooseItem(index) {
    if (!currentItems.length) {
      return;
    }

    var item = currentItems[index];
    if (item) {
      $searchInput.val(item.value);
      hideSuggestions();
      if (item.autoSubmit) {
        $searchInput.closest("form").trigger("submit");
      }
    }
  }

  function renderSuggestions(data) {
    currentItems = [];
    $list.empty();

    var words = data.suggestions || [];
    var queries = data.queries || [];

    words.forEach(function (item) {
      currentItems.push({
        value: item.word,
        autoSubmit: false,
      });
      var $item = $(
        '<li class="ssai-suggestions__item"><span class="ssai-suggestions__label"></span><span class="ssai-suggestions__meta"></span></li>'
      );
      $item.find(".ssai-suggestions__label").text(item.word);
      $item
        .find(".ssai-suggestions__meta")
        .text(parseFloat(item.weight).toFixed(2));
      $list.append($item);
    });

    queries.forEach(function (item) {
      currentItems.push({
        value: item.query,
        autoSubmit: true,
      });
      var $item = $(
        '<li class="ssai-suggestions__item"><span class="ssai-suggestions__label"></span><span class="ssai-suggestions__meta"></span></li>'
      );
      $item.find(".ssai-suggestions__label").text(item.query);
      $item.find(".ssai-suggestions__meta").text(item.count + "×");
      $list.append($item);
    });

    if (!currentItems.length) {
      $list.append(
        '<li class="ssai-suggestions__empty">' +
          (ajaxData.i18n ? ajaxData.i18n.noSuggestions : "No suggestions") +
          "</li>"
      );
    }

    $list.show();
  }

  function fetchSuggestions(term) {
    if (!term || term.length < 2) {
      hideSuggestions();
      return;
    }

    var localRequestId = ++requestId;
    $.getJSON(
      ajaxUrl,
      {
        action: "ssai_autocomplete",
        term: term,
        nonce: nonce,
      },
      function (response) {
        if (!response || response.success !== true) {
          return;
        }

        if (localRequestId !== requestId) {
          return;
        }

        renderSuggestions(response.data || {});
      }
    );
  }

  $searchInput.on("input", function () {
    var term = $(this).val().trim();
    if (!term || term.length < 2) {
      hideSuggestions();
      return;
    }

    fetchSuggestions(term);
  });

  $searchInput.on("keydown", function (event) {
    if (!$list.is(":visible")) {
      return;
    }

    if (event.key === "ArrowDown") {
      event.preventDefault();
      selectItem(currentIndex + 1);
    } else if (event.key === "ArrowUp") {
      event.preventDefault();
      selectItem(currentIndex - 1);
    } else if (event.key === "Enter") {
      if (currentIndex >= 0) {
        event.preventDefault();
        chooseItem(currentIndex);
      }
    } else if (event.key === "Escape") {
      hideSuggestions();
    }
  });

  $list.on("mouseenter", ".ssai-suggestions__item", function () {
    var index = $(this).index();
    selectItem(index);
  });

  $list.on("mousedown", ".ssai-suggestions__item", function (event) {
    event.preventDefault();
    var index = $(this).index();
    chooseItem(index);
  });

  $(document).on("click", function (event) {
    if (
      !$(event.target).closest($container).length &&
      event.target !== $searchInput[0]
    ) {
      hideSuggestions();
    }
  });
});
