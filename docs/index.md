---
title: "Square POS"
section: "extensions"
sortOrder: 999
---

## Installation

You can install the extension via Composer using the following command:

```bash
composer require igniterlabs/ti-ext-squarepos
```

Run the database migrations to create the required tables:

```bash
php artisan igniter:up
```

Staff who manage the connection need the **Manage Square POS for TastyIgniter settings** permission.

## Getting started

Open **Manage > Settings** and choose **Square POS Settings**.

1. Set **Square environment** to **Sandbox** while you are testing. A live site should use **Production**.
2. In **Sandbox**, enter the **Application ID** and **Access token** from the Square developer dashboard, then click **Save**. In **Production**, click **Connect with Square** and approve access in Square.
3. Open the **Location Mappings** tab. Pair each TastyIgniter location with a Square location. Every active Square location is listed. Disconnecting clears these mappings.
4. Choose the **System of record**, then turn on the catalog, inventory, and order switches you want.
5. Click **Save**.

Use **View sync history** to read the notes from recent runs. When a run fails and **Sync notification email** is filled in, that address receives the failure email.

## Settings

These fields are on the **General** tab after the site is connected.

| Setting | Description |
|---|---|
| **System of record** | Square overwrites TastyIgniter menu items, or TastyIgniter updates the Square catalog. |
| **Square environment** | Sandbox for testing, production for a live site. |
| **Sync catalog on a schedule** | Run menu sync in the background. |
| **Catalog sync interval** | Every 15 minutes, every 30 minutes, or every hour. |
| **Sync Inventory** | Pull Square stock, and push it back when TastyIgniter is the authority for that item. |
| **Inventory sync interval** | How often stock is checked between menu syncs. |
| **Handle Missing Menu Items** | Disable or delete a TastyIgniter menu when its Square item is removed. |
| **Sync notification email** | Receives a message when a sync fails. |
| **Sync order fulfillment** | Send online orders to Square and apply completed or canceled fulfillment back here. |
| **Order sync interval** | How often fulfillment is checked. |
| **Completed order status** | Status applied when Square completes the fulfillment. |
| **Canceled order status** | Status applied when Square cancels the fulfillment. |

## For restaurant owners

This section describes what changes on your menu, in your stock, and on an order after a sync. The system of record is the side that wins when both TastyIgniter and Square have a copy of the same thing.

### Menus, categories, and options

A scheduled menu sync goes one way.

**Square POS (overwrite TastyIgniter menu items).** Square is the source. Each run copies Square categories, modifiers, and items onto the matching records in TastyIgniter. The item name, description, and price follow Square, and the item's categories, options, locations, and image are updated to match. An item stays as you left it in TastyIgniter when **Sync with Square** is turned off on that menu. A Square item that does not already match a TastyIgniter menu is left in Square until you import it.

**TastyIgniter (update Square catalog).** TastyIgniter is the source. Each run sends your categories, menu options, and menu items to Square, and Square's copy is updated from what you saved. A menu item is included when **Sync with Square** is on, and also when that switch has never been set. Turning the switch off keeps that item out of later syncs.

On the menu form, the **Square** tab holds three fields:

| Field | What it does |
|---|---|
| **Sync with Square** | Includes this menu item in scheduled sync. |
| **SKU** | Matched to the Square variation SKU. |
| **Inventory override** | Follow the system of record, always send this item's stock to Square, or keep Square as the stock authority. |

From the menu list you can select items and use **Square sync** to enable or disable sync for the selection.

**Import Items** pulls the Square catalog into TastyIgniter for that run, even when the saved system of record is TastyIgniter. A Square item with a SKU and no local menu is created, switched on, and opted into sync. A menu that already matches is updated and opted into sync as well. An item with no SKU is skipped. An item whose SKU already belongs to a different Square item is skipped, so two Square items cannot share one TastyIgniter menu.

**Export Items** sends categories, modifiers, and menu items to Square for that run, including menu items whose sync switch is off.

When Square is the source and someone deletes an item in Square, **Handle Missing Menu Items** decides what happens here. **Disable missing items** turns the TastyIgniter menu off and takes it out of sync. **Delete missing items** removes the TastyIgniter menu.

### Stock

Turn on **Sync Inventory** when register sales in Square should update the quantities guests see online, or when the quantities you keep in TastyIgniter should update Square.

Each inventory run reads Square first, then sends quantities back. Which side is kept depends on the item:

- **Follow system of record.** Square as the system of record overwrites the TastyIgniter quantity. TastyIgniter as the system of record sends the local quantity to Square, and a local change that has not been sent yet is left in place.
- **Push this item's stock to Square.** The TastyIgniter quantity is sent to Square for this item.
- **Square is the stock authority.** The Square count overwrites the TastyIgniter quantity, and the local quantity is not sent.

Import uses Square as the stock authority for items that follow the system of record. Export uses TastyIgniter. A menu sync also moves stock when inventory sync is on. The inventory interval runs again on its own, so sales at the register are picked up between menu syncs.

Stock is updated only for mapped locations, and only for menu items that already have a Square variation.

### Orders

Turn on **Sync order fulfillment** when online orders should appear in Square, and when completing or canceling them in Square should update TastyIgniter.

Each order run does two things:

1. Online orders from the last 7 days that are not in Square yet are created there, with pickup or delivery fulfillment. The TastyIgniter order number is stored on the Square order.
2. Orders already in Square are checked. When Square marks the fulfillment completed or canceled, the TastyIgniter order moves to the **Completed order status** or **Canceled order status** you selected. An order that is already on that status is left unchanged. Orders that are already completed or canceled in TastyIgniter are not checked again.

Orders entered on the Square register stay in Square. Refunds taken in Square do not change the TastyIgniter order.

Order sync does not follow the menu system of record. It runs only when the order switch is on.

## Running a sync from the command line

The scheduler runs these commands when the matching switch is on. You can run the same commands yourself:

```bash
php artisan square:sync --menus
php artisan square:sync --inventory
php artisan square:sync --orders
```

`--menus` syncs categories, modifiers, and menu items in the direction set by the system of record. `--inventory` syncs stock. `--orders` syncs fulfillment, and records that order sync is disabled when that switch is off. Each command prints the notes from that run, and prints the error when the run fails.
