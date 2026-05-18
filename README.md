# Kaina24 WooCommerce XML Feed įskiepis

Šis WordPress įskiepis:
- kas **12 val.** generuoja WooCommerce produktų XML failą,
- sukuria viešą URL: `https://jusu-domenas.lt/kaina24-feed.xml`,
- generuoja XML pagal Kaina24 pateiktą `products/product` struktūrą.

## Diegimas

1. Įkelkite failą `kaina24-xml-feed.php` į `wp-content/plugins/kaina24-xml-feed/` katalogą.
2. WordPress administracijoje aktyvuokite įskiepį.
3. Eikite į **WooCommerce → Kaina24 XML Feed**.
4. Nukopijuokite Feed URL ir pateikite Kaina24.lt sistemai.

## Laukų mapinimas

- `product[@id]` → produkto arba variacijos ID
- `title` → produkto pavadinimas (CDATA)
- `price` → WooCommerce kaina (`2` skaičiai po kablelio)
- `condition` → `new`
- `stock` → likutis (`stock_quantity`, jei nėra: `0`)
- `ean_code` → `_ean` meta
- `additional_eans/ean` → `additional_eans` meta (kableliais / kabliataškiais atskirtos reikšmės)
- `manufacturer_code` → SKU
- `manufacturer` → `brand` meta arba `product_brand` taksonomija
- `model` → `model` meta (fallback: SKU)
- `image_url` / `additional_images/image` → pagrindinis + galerijos paveikslėliai
- `product_url` → produkto URL
- `category_*` → pirma produkto kategorija
- `description` → description/short description (CDATA)
- `specs/spec` → produkto atributai
- `short_message` → `kaina24_short_message` meta (max 80)
- `delivery` (home/parcel/pickup) → custom meta laukai:
  - `home_delivery_days`, `home_delivery_price`
  - `parcel_delivery_days`, `parcel_delivery_price`
  - `pickup_days`, `pickup_price`

> Jei dalies custom meta nėra, naudojami saugūs numatytieji `working_days` / `price`.
