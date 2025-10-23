# 🧠 Smart Search AI — Self-Learning Search Plugin for WordPress

**Smart Search AI** is a lightweight, self-improving search engine built in **pure PHP + MySQL**, seamlessly integrating with the default WordPress search.  
It continuously analyzes user queries and clicks to make search results smarter and more relevant — all without any external APIs or cloud AI.

---

## 🚀 Features

✅ **Self-Learning Algorithm** — every search and click updates internal word weights in real time.  
✅ **Relevance Boosting** — results are sorted by both word relevance and click frequency.  
✅ **No Dependencies** — no API keys, no external AI, no bloat — works entirely on your server.  
✅ **Real-Time Statistics** — admin dashboard with top words and latest search logs.  
✅ **Privacy-Friendly** — all data stays within your WordPress database.  

---

## 🧩 How It Works

1. A visitor searches for something using the default WordPress search.
2. The plugin logs the query and increases the weight of words found in it.
3. When a visitor clicks a result, the plugin strengthens the weight of those words even more.
4. Future searches are automatically ranked higher for frequently clicked or relevant words.

---

## ⚙️ Installation

1. Upload the plugin folder to `/wp-content/plugins/smart-search-ai/`
2. Activate **Smart Search AI** from the WordPress admin panel.
3. Use the default search — the plugin will start learning automatically.

---

## 🧾 Admin Dashboard

Go to **WordPress → Smart Search AI** to view analytics:

| Section | Description |
|----------|--------------|
| 🔤 **Top Words** | Displays the most frequently used and weighted search terms |
| 🧾 **Recent Searches** | Shows the latest user queries and clicked posts |

---

## 🛠️ Technical Overview

- Hooks into `pre_get_posts` to modify the default search query dynamically  
- Adds two custom tables:  
  - `wp_ai_words` — stores each unique word, its current weight, and last usage time  
  - `wp_ai_search_log` — stores user queries, timestamps, and clicked post IDs  
- Handles AJAX requests for click tracking:
  ```php
  wp_ajax_ssai_register_click
  wp_ajax_nopriv_ssai_register_click
