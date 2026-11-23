# PainDraw REDCap external module
(c) 2026 OPEN // Open Patient data Explorative Network

**Author:** Anders Kristian Haakonsson, anders.kristian.haakonsson@rsyd.dk

## 1. Overview

PainDraw is a REDCap external module that enables collection of **vector-based drawing data** from users directly within REDCap instruments and surveys.

The module was originally designed to capture **experienced pain** drawn on anatomical background images. However, it can be used for any drawing task where users mark regions or patterns on an image (e.g. pain maps, body charts, diagrams, or other templates).

Each drawing can be stored as:

- A **JSON file** containing stroke-level vector information (coordinates, colors, tools, etc.), and
- Optionally, a **PNG image** representing the final drawing, as seen on the screen.

The JSON output is designed to be analysed using the **PainDrawR** package in R.

## 2. Key Features

- Embed an interactive **HTML5 canvas** on REDCap instruments and surveys.
- Use a **custom background image** (e.g. anatomical template, map, diagram).
- Support for two drawing tools:
  - **Spray** (default) – draws multiple points per coordinate to form sprayed regions.
  - **Pen** – draws continuous lines.
- Configurable drawing parameters:
  - Line/spray width
  - Color (HEX)
  - Transparency (alpha)
  - Spray radius and density
- Automatic saving of:
  - Vectorized stroke data as **JSON**.
  - Optional **PNG** snapshot of the completed drawing.
- Support for both **mouse** and **touch** input.

## 3. Installation

1. Copy the PainDraw module files to your REDCap external modules directory, following standard REDCap conventions.
   The directory name must end with the module version, for example `paindraw_v1.0.0`. When installing from a
   GitHub download, rename the extracted folder accordingly. The version is not stored anywhere else in the code:
   it is read from the directory name and written to every saved drawing (`module_version`). Drawings are not
   saved if the directory name has no version.
2. Enable the module at the **system level** in REDCap (Control Center → External Modules).
3. Enable PainDraw for the specific project(s) where you want to use it (Project Setup → External Modules → Enable PainDraw).

Refer to the general REDCap external modules documentation for details on installing and enabling modules.

## 4. Project Configuration
PainDraw is configured per project under:

> **Project Setup → External Modules → PainDraw → Configure module**

The main configuration is defined through a repeatable setting called:

> **“group_of_fields – Configure PainDraw drawing canvas (one per image)”**

Each group defines one PainDraw canvas linked to one background image.

### 4.1 Required settings (per canvas)

- **Instrument**
  - Select the instrument where the PainDraw canvas should appear.
  - Only **one** PainDraw canvas is allowed per instrument.

- **Canvas field**
  - Select a **file upload** field on the chosen instrument.
  - PainDraw will replace the standard file upload UI with the drawing canvas and background image.
  - The JSON data will be stored back into this field as a file.

- **Image file**
  - Upload the image file to use as the canvas background.
  - Supported MIME types include e.g.: `image/jpeg`, `image/png`, `image/webp`, `image/avif`, `image/bmp`.
  - If the file format is not supported, the module will display a configuration error instead of the canvas.

### 4.2 Optional settings

- **Image store field**
  - Select an additional **file upload** field on the same instrument, where a PNG copy of the final drawing can be stored when the record is saved.
  - This allows you to keep both a visual copy (PNG) and a vector representation (JSON) of the user input.

- **Canvas width / Canvas height**
  - Specify the canvas dimensions in pixels.
  - If left blank, dimensions will be derived from the background image.
  - These values are captured in the JSON metadata.

- **Drawing tool**
  - Choose the default drawing tool:
    - `spray` (default)
    - `pen`

- **Line width**
  - Width of lines or spray points in pixels (default: 3).

- **Line color**
  - Color of lines or spray points in HEX format (default: `#fc3d03`, red).
  - If an invalid HEX value is provided, the default color will be used.

- **Transparency**
  - Transparency of drawn lines or spray points expressed as **percent** (`0–100`).
  - Default: `0` (fully opaque) for `pen`, `90` for `spray`.
  - The module internally converts this to an alpha value (`0–1`) for rendering.
  - Each pen stroke is drawn with uniform transparency. Overlapping strokes add up.

### 4.3 Internal/technical settings

The module also maintains several internal fields:

- `format_error` – Flag for unsupported image formats.
- `image_md5` – MD5 checksum of the uploaded background image.
- `image_file_name` – Background image file name.

These are used to ensure consistency and robustness of the configuration and data output, and are not ment to be edited manually.


## 5. User Experience

When PainDraw is configured for an instrument or survey:

- The user sees the uploaded **background image** embedded in an interactive canvas.
- The configured drawing settings (tool, color, width, transparency, spray parameters) are automatically applied.
- The module supports both **mouse** and **touch** input:
  - Mouse: click and drag to draw.
  - Touch: taps/drags are translated into mouse events for drawing.

The canvas UI includes:

- **Reset** button  
  Clears all strokes and reloads the background image. Previously stored strokes are discarded for that session.

- **Download copy** button  
  Allows the user to download a local PNG file (`paindraw_copy.png`) of the current drawing for their own reference.

Drawing data is stored when the user **saves** or **submits** the instrument/survey.


## 6. Data Storage and Export

### 6.1 JSON stroke data

On record save (data entry or survey submission), the module:

1. Reads the hidden POST field `paindraw_stored_strokes` containing JSON data generated by the JavaScript frontend.
2. Validates the JSON server-side to enforce:
   - Correct data types and ranges.
   - Valid colors (HEX) and numeric parameters.
   - Valid coordinate pairs for strokes.
3. Constructs a JSON document that follows [JSON schema 1.0](schema/paindraw_schema_1.0.json) (simplified):

```json
{
  "schema_version": "1.0",
  "module_version": "<x.y.z, from the module directory name>",
  "app": "paindrawEM",
  "drawing_id": "PID<Project>-R<Record>-F<CanvasField>-E<Event>-I<Instance>",
  "timestamp": "<UTC, YYYY-MM-DDTHH:MM:SSZ>",
  "width": <canvas_width>,
  "height": <canvas_height>,
  "units": "px",
  "template_file": "<template_file_name>",
  "template_md5": "<32 hex characters>",
  "strokes": [ /* validated stroke data: stroke_id, draw_input_type, tool, color, line_width,
                  spray_density, spray_radius, alpha, scale_factor, device_pixel_ratio, points */ ]
}
```

   The document is checked against the schema's rules before it is written. If it does not conform
   (for example the module directory name has no `_vX.Y.Z` version, the template image has no checksum,
   or there are no strokes), nothing is saved and an error is written to the PHP error log.

4. Writes this JSON to a temporary file and stores it using REDCap::storeFile(...).
5. Links the stored file to the canvas field as a file upload entry.

You can export the JSON files via:

- The standard data export tools in REDCap: **Data Exports, Reports, and Stats**
- The API (for file exports from file upload fields), or
- Manual download from the record’s file upload field.

### 6.2 PNG image (optional)

If an **image store field** is configured, the module also:

1. Reads `paindraw_stored_png` from the POST data, containing a `data:image/png;base64,...` representation of the canvas.
2. Validates and decodes the base64 payload.
3. If the **Imagick** PHP extension is available:
   - Loads the binary data into Imagick.
   - Sets image properties such as:
     - `Author` (module version)
     - `Draw_id` (unique drawing ID)
   - Normalizes and re-outputs the image as PNG.
4. Stores the PNG using `REDCap::storeFile(...)`.
5. Links the image file to the configured **image store field**.

This enables downstream workflows that rely on either the visual representation (PNG) or the structured JSON.



## 7. Data Analysis

The JSON data generated by PainDraw is designed to be analysed with the **PainDrawR** package in R.

PainDrawR can:

- Read and parse PainDraw JSON files.
- Reconstruct strokes, tools, colors, and canvas layouts.
- Aggregate or visualize drawing data (e.g. pain distribution maps).

For installation instructions, vignettes, and examples, please refer to:

- PainDrawR GitHub repository: <https://github.com/steenharsted/PainDrawR>


## 8. Citation
If you use PainDraw and PainDrawR in research, please refer to the recommended citation(s) for both the module and the R package (to be added here when available).

## 9. Limitations and Notes

- Each instrument can host **only one** PainDraw canvas configuration.
- Canvas rendering and drawing rely on modern browser support for **HTML5 canvas** and JavaScript.
- The module does not perform any clinical interpretation of drawings; it only captures and stores data.
- External Module support for i18n is not fully implemented. Only tools facing the participant can be translated. 


## 10. License

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.
