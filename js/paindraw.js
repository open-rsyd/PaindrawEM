$(document).ready(function(){

// Add CSS to page head handeling responsiveness of canvas
  $('head').append(`
    <style>
      .paindraw-canvas {
        outline: 1px solid black;
        touch-action: none;
        max-width: 100%;
        height: auto;
        display: block;
      }
    </style>
  `);

// Find the canvas field
const canvas_field_tr = $("#" + paindraw_cfg.canvas_field + "-tr" ) ;
if (! canvas_field_tr.length ) {
  return ;
} 

if( ! paindraw_cfg['img_url'] ) {
  if( paindraw_cfg['survey'] ) {
    canvas_field_tr.empty() ;
    return ;
  }
  
  const canvas_field_data_td = canvas_field_tr.find("div.sig-imgp") ;
  canvas_field_data_td.addClass('red').html(
    "<b>PainDraw configuration error</b>:\
    Image file is an unsupported file format. Please upload a new file in External Module configuration.") ;
  return ;
}

// Using jQuerry to construst DOM elements used for PainDraw canvas  
const paindraw_canvas = $("<canvas>", {
    id: "drawingCanvas",
    class: "paindraw-canvas",
}).attr("width", paindraw_cfg.canvas_width ).attr("height", paindraw_cfg.canvas_height);

// Create buttons
const paindraw_resetBtn = $("<button>", {
    type: "button",
    id: "pscResetButton",
    class: "btn btn-danger",
    text: paindraw.tt("reset_btn")   //"Reset"
});

const paindraw_dlBtn = $("<button>", {
    type: "button",
    id: "pscDlButton",
    class: "btn btn-primaryrc",
    text: paindraw.tt('download_btn') // "Download copy"
});

// Create td and append elements
const td = $("<td>", {
    class: "labelrc col-12 p-0",
    colspan: 2
}).append(
    paindraw_canvas,
    $("<br>"),
    paindraw_resetBtn,
    paindraw_dlBtn
);

// Create tr and append td
const paindraw_canvas_tr = $("<tr>").append(td);

// Modify the canvas field
const canvas_field_tds = canvas_field_tr.children("td");

const canvas_nested_table = $("<table>", {
  class: "col-12 p-0"
});

const canvas_nested_table_r1 = $("<tr>");
canvas_nested_table_r1.append(canvas_field_tds);
canvas_nested_table.append(canvas_nested_table_r1).append(paindraw_canvas_tr);

const canvas_wrapper_td = $("<td>", {
  colspan: 2,
  class: "labelrc p-0 col-12",
}).append(canvas_nested_table);

canvas_field_tr.empty().append(canvas_wrapper_td);

const canvas_field_data_td = canvas_field_tr.find("div.sig-imgp") ;
canvas_field_data_td.remove() ;


$("#pscResetButton").click(function() {
  paindrawResetCanvas() ;
}); 

$("#pscDlButton").click(function() {
  paindrawDlCanvas() ;   
});

// Make a form object to store cordinates object.

const paindraw_stored_strokes = $("<input>", {
  name: "paindraw_stored_strokes",
  id: "paindraw_stored_strokes",
  type: "hidden"
})

const paindraw_stored_png = $("<input>", {
  name: "paindraw_stored_png",
  id: "paindraw_stored_png",
  type: "hidden"
});


$("#form").append(paindraw_stored_strokes).append(paindraw_stored_png) ;

// PainDraw main code

// Choosing to work with the canvas in vanilla js 
const canvas_field = document.getElementById('drawingCanvas');
let ctx = canvas_field.getContext('2d');
let drawing = false;
let storedStrokes = []; // To store the drawn points along with strokeId
let currentStroke = [] ; // To track current stroke
let currentStrokeSettings = []; // To store settings for each stroke
let currentStrokeId = 0; // Stroke identifier

// Settings for spray effect, updated from R inputs
let sprayDensity = paindraw_cfg['spray_density'] ; 
let sprayRadius = paindraw_cfg['spray_radius'] ; 
let lineWidth = paindraw_cfg['line_width'] ;
let lineColor = paindraw_cfg['line_color'] ;
let alpha = paindraw_cfg['alpha'] ;
let backgroundLoaded = false ;
let drawInputType = 'mouse' ;
let lastX = 0, lastY = 0 ;
let tool = paindraw_cfg['draw_tool'] ;
// Canvas state at the start of a pen stroke. The stroke is redrawn on top of
// it on every move, so each pen stroke is drawn exactly once with the set alpha.
let penSnapshot = null ;

// Set values
ctx.lineCap = 'round';
ctx.lineJoin = 'round';

// Saving the original canvas size = img default
const originalCanvasWidth  = paindraw_cfg.canvas_width;
let rect = null; 
let scaleFactor = 1;

function updateCanvasViewMetrics() {
  rect = canvas_field.getBoundingClientRect();
  scaleFactor = rect.width / originalCanvasWidth
};

// Load a new background image
function loadBackgroundImage(url) {
  let img = new Image();
  img.onload = function() {
    ctx.globalAlpha = 1;
    ctx.clearRect(0, 0, canvas_field.width, canvas_field.height);
    ctx.drawImage(img, 0, 0, canvas_field.width, canvas_field.height);
    backgroundLoaded = true;
    updateCanvasViewMetrics();
  };
  img.onerror = function() {
    alert('Failed to load background image. Using blank canvas.');
    backgroundLoaded = true;
  };
  img.src = url;
}

// Load the default background image initially
const imageUrl = paindraw_cfg['img_url'] ;
loadBackgroundImage(imageUrl); // The URL will be relative to the www folder

canvas_field.addEventListener('mousedown', function(e) {
  if (!backgroundLoaded) return;
  updateCanvasViewMetrics(); 
  drawing = true;
  currentStrokeId++; // Increment stroke ID to mark a new stroke

  const x = Math.round((e.clientX - rect.left) / scaleFactor );
  const y = Math.round((e.clientY - rect.top) / scaleFactor );

  // Start the spray at the initial point of the stroke
      
  currentStrokeSettings = {
    strokeId: currentStrokeId, // i
    drawInputType: drawInputType,
    tool: tool, // t
    color: lineColor, // c
    lineWidth: lineWidth, // bw
    sprayDensity: sprayDensity, //pd
    sprayRadius: sprayRadius, // pr
    alpha: alpha, // a note [0 - 1]
    scaleFactor: scaleFactor,
    dpr: window.devicePixelRatio 
  };

  if (tool === 'spray') {   
    doSpray(x, y);
  } else {
    penSnapshot = ctx.getImageData(0, 0, canvas_field.width, canvas_field.height);
    lastX = x; lastY = y;
    doPen(x, y);
  }
});

canvas_field.addEventListener('mousemove', function(e) {
  if (drawing && backgroundLoaded) {
    
    const x = Math.round((e.clientX - rect.left) / scaleFactor );
    const y = Math.round((e.clientY - rect.top) / scaleFactor );
    
    if ( tool === 'spray') {
      doSpray(x, y);
    } else {
      doPen(x, y);
    }
  }
});



  // Ends the current stroke and stores it. Safe to call when no stroke is active.
function endStroke() {
  if (drawing) {
    drawing = false;
    penSnapshot = null;

    currentStrokeSettings.p = currentStroke ;
    storedStrokes = storedStrokes.concat(currentStrokeSettings) ;
    paindraw_stored_strokes.val(JSON.stringify(storedStrokes));
    paindraw_stored_png.val( canvas_field.toDataURL('image/png') );
    
    currentStroke = [] ;
    currentStrokeSettings = [];
    setDataEntryFormValuesChanged() ;
  }
}

canvas_field.addEventListener('mouseup', endStroke );
document.addEventListener('mouseup', endStroke );


canvas_field.addEventListener("touchstart", function (e) {
  drawInputType = 'touch';
  var touch = e.touches[0];
  var mouseEvent = new MouseEvent("mousedown", {
    clientX: touch.clientX,
    clientY: touch.clientY
  });
  canvas_field.dispatchEvent(mouseEvent);
}, false);

canvas_field.addEventListener("touchend", function (e) {
  drawInputType = 'mouse';
  var mouseEvent = new MouseEvent("mouseup", {});
  canvas_field.dispatchEvent(mouseEvent);
}, false);

canvas_field.addEventListener("touchmove", function (e) {
  var touch = e.touches[0];
  var mouseEvent = new MouseEvent("mousemove", {
    clientX: touch.clientX,
    clientY: touch.clientY
  });
  canvas_field.dispatchEvent(mouseEvent);
}, false);

function doSpray(x, y) {
  // Spray around the central point
  ctx.globalAlpha = alpha;
  ctx.fillStyle = lineColor;
  for (let i = 0; i < sprayDensity; i++) {
    let angle = Math.random() * 2 * Math.PI;
    let radius = Math.random() * sprayRadius;
    let offsetX = radius * Math.cos(angle);
    let offsetY = radius * Math.sin(angle);
    let sprayX = x + offsetX - ( lineWidth * 0.5 );
    let sprayY = y + offsetY - ( lineWidth * 0.5 );
    ctx.fillRect(sprayX, sprayY, lineWidth, lineWidth);
  }

  currentStroke.push([x,y]);
}

function doPen(x, y) {
  currentStroke.push([x,y]);
  lastX = x; lastY = y;

  // Restore the canvas as it was before this stroke, then draw the whole
  // stroke once. Stroking the growing path repeatedly would stack the alpha.
  ctx.putImageData(penSnapshot, 0, 0);
  ctx.globalAlpha = alpha;
  ctx.lineWidth = lineWidth;
  ctx.strokeStyle = lineColor;
  ctx.beginPath();
  ctx.moveTo(currentStroke[0][0], currentStroke[0][1]);
  for (const [px, py] of currentStroke) {
    ctx.lineTo(px, py);
  }
  ctx.stroke();
}

function paindrawResetCanvas() {
  backgroundLoaded = false ;

  paindraw_stored_strokes.removeAttr("value") ;
  paindraw_stored_png.removeAttr('value') ;

  storedStrokes = []
  currentStroke = [] ; // To track current stroke
  currentStrokeSettings = []; // To store settings for each stroke
  currentStrokeId = 0; // Stroke i
  
  loadBackgroundImage(imageUrl) ;

}

function paindrawDlCanvas() {
  
  const MIME_TYPE = 'image/png' ;
  const img_url = canvas_field.toDataURL(MIME_TYPE) ;
  var dlLink = document.createElement('a');
  dlLink.download = "paindraw_copy.png";
  dlLink.href = img_url;
  dlLink.dataset.downloadurl = [MIME_TYPE, dlLink.download, dlLink.href].join(':');

  document.body.appendChild(dlLink);
  dlLink.click();
  document.body.removeChild(dlLink);
  
}

});


