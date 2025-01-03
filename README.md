# AutoDNS Price List Module for WHMCS

This WHMCS module fetches and displays the AutoDNS XML price list in the WHMCS admin area. It allows administrators to view domain pricing with options to apply margins and rounding adjustments for pricing simulation purposes.

## Features
- Fetches the AutoDNS XML price list using API credentials.
- Displays domain pricing in a sortable table.
- Allows applying margins to prices and rounding them to whole numbers or tens.
- Interactive domain search filter.
- **For viewing purposes only.**

## Requirements
- WHMCS 7.10 or later
- AutoDNS account with API access enabled
- PHP 7.4 or later

## Setup Instructions
1. **Activate the XML Price List in AutoDNS**
   - Log in to your AutoDNS account.
   - Navigate to **Services > Products** and select **APIs & Services**.
   - Enable the **XML Price List** service.
   - Enter the IP addresses that are allowed to access the XML price list.
   - Confirm by clicking **OK**.
   - **Note**: The XML price list is regenerated daily at 22:00 (Europe/Berlin time). It may take up to 24 hours after activation for the price list to become available.

2. **Install the WHMCS Module**
   - Clone or download the repository.
   - Upload the contents to your WHMCS `/modules/addons/dd_autodns_pricelist/` directory.
   - In the WHMCS admin area, go to **Setup > Addon Modules**.
   - Find the **DD: AutoDNS Price List Module** and click **Activate**.

3. **Configure the Module**
   - Go to the module settings and enter the **username** and **password** provided by AutoDNS when enabling the XML price list.
   - Save the configuration.

## Usage
1. Go to **Addons > AutoDNS Price List** in the WHMCS admin area.
2. The page will display the current AutoDNS XML price list.
3. Use the input fields to apply margins and rounding options to simulate pricing.
4. To import the domains and pricing into WHMCS, navigate to **WHMCS - Utilities - Registrar TLD Sync**.

## Configuration Fields
- **Username**: AutoDNS username provided after enabling the XML price list.
- **Password**: AutoDNS password provided after enabling the XML price list.

## Example

### Initial Table View

| Domain       | Create | Renew | Transfer | Restore | Currency | Period |
|--------------|--------|-------|----------|---------|----------|--------|
| example.com  | 10.00  | 10.00 | 10.00    | 70.00   | USD      | 1 year |
| example.net  | 12.00  | 12.00 | 12.00    | 80.00   | USD      | 1 year |

### Applying Margin and Rounding
1. Enter a margin percentage (e.g., `20%`) in the margin input field.
2. Select a rounding option (`No rounding`, `Round to whole numbers`, or `Round to tens`).
3. The table will automatically update the prices based on the applied margin and rounding.

## Contributing
Pull requests are welcome. For major changes, please open an issue first to discuss what you would like to change.
