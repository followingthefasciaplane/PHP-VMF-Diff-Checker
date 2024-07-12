## 1. The structure of .vmf

The code structure in a VMF file is simple and easy to understand. It is also common in many other aspects of the Source engine.

```plaintext
// This is a comment.
ClassName_1
{
      "Property_1" "Value_1"
      "Property_2" "Value_2"
      ClassName_2
      {
            "Property_1" "Value_1"
      }
}
```

### Data Types

- **int**: Any integer value.
- **dec**: Any decimal value.
- **sz**: Any string of characters.
- **bool**: A Boolean value of true/false in binary.
- **vertex**: An XYZ location given by 3 decimal values separated by spaces.
- **rgb**: A color value using 3 integers between 0 and 255 separated by spaces corresponding to the Red channel, Green channel, and Blue channel, respectively.

### Usual Structure

The usual structure of a VMF is as follows:

```plaintext
versioninfo{}
visgroups{}
world{}
entity{}
hidden{}
cameras{}
cordon{}
```

## 2. Version info

This Class details information for Hammer on what version created the file and how many times it has been saved. It is essentially the file header and contains no information relevant to the map itself.

```plaintext
versioninfo
{
      "editorversion" "400"
      "editorbuild" "3325"
      "mapversion" "0"
      "formatversion" "100"
      "prefab" "0"
}
```

- **editorversion (int)**: The version of Hammer used to create the file.
- **editorbuild (int)**: The patch number of Hammer the file was generated with.
- **mapversion (int)**: The number of times the file has been saved.
- **formatversion (int)**: Unknown (most likely the VMF file format version).
- **prefab (bool)**: Whether it is a full map or simply a collection of prefabricated objects.

## 3. VisGroups

This Class contains all the unique VIS group definitions in Hammer, along with their structure and properties.

```plaintext
visgroups
{
      visgroup
      {
            "name" "Tree_1"
            "visgroupid" "5"
            "color" "65 45 0"
      }
      visgroup
      {
            "name" "Tree_2"
            "visgroupid" "1"
            "color" "60 35 0"
            visgroup
            {
                  "name" "Branch_1"
                  "visgroupid" "2"
                  "color" "0 192 0"
            }
            visgroup
            {
                  "name" "Branch_2"
                  "visgroupid" "3"
                  "color" "0 255 0"
                  visgroup
                  {
                        "name" "Leaf"
                        "visgroupid" "4"
                        "color" "255 0 0"
                  }
            }
      }
}
```

- **name (sz)**: The name of the group.
- **visgroupid (int)**: A unique value among all other visgroup ids.
- **color (rgb)**: A color for the group, can be applied to brush outlines in Hammer.

## 4. Viewsettings

This class contains the map-specific view properties used in Hammer.

```plaintext
viewsettings
{
      "bSnapToGrid" "1"
      "bShowGrid" "1"
      "bShowLogicalGrid" "0"
      "nGridSpacing" "64"
      "bShow3DGrid" "0"
}
```

- **bSnapToGrid (bool)**: Whether the map has the grid snapping feature enabled.
- **bShowGrid (bool)**: Whether the map is showing the 2D grid.
- **bShowLogicalGrid (bool)**: Changes whether the hidden "Logical View" should show a grid.
- **nGridSpacing (int)**: The value the grid lines are spaced at.
- **bShow3DGrid (bool)**: Whether the map is showing the 3D grid.

## 5. World

The world Class contains all the world brush information for Hammer.

```plaintext
world
{
      "id" "1"
      "mapversion" "1"
      "classname" "worldspawn"
      "skyname" "sky_wasteland02"
      Solid{}
      Hidden{}
      Group{}
}
```

- **id (int)**: A unique value among other world Class ids.
- **mapversion (sz)**: A reiteration of the mapversion from the versioninfo Class.
- **classname (worldspawn)**: States what type of entity the world is.
- **skyname (sz)**: The name of the skybox to be used.
- **solid{}**
- **group{}**
- **hidden{}**

## 6. Solid

This Class represents a single brush in Hammer.

```plaintext
solid
{
      "id" "1"
      side{}
      editor{}
}
```

- **id (int)**: A unique value among other solids' IDs.
- **side{}**
- **editor{}**

## 7. Side

This Class defines all the data relevant to one side of a brush.

```plaintext
side
{
      "id" "6"
      "plane" "(512 -512 -512) (-512 -512 -512) (-512 -512 512)"
      "material" "BRICK/BRICKFLOOR001A"
      "uaxis" "[1 0 0 0] 0.25"
      "vaxis" "[0 0 -1 0] 0.25"
      "rotation" "0"
      "lightmapscale" "16"
      "smoothing_groups" "0"
      "contents" "1"
      "flags" "0"
      dispinfo{}
      vertices_plus
      {
            "v" "-387.528 -192 70"
            "v" "-448 -192 70"
            "v" "-448 -192 64"
            "v" "-387.528 -192 64"
      }
}
```

- **id (int)**: A unique value among other sides ids.
- **plane (vertex)**
- **material (sz)**: The directory and name of the texture the side has applied to it.
- **uaxis (dec)**
- **vaxis (dec)**
- **rotation (dec)**: The rotation of the given texture on the side.
- **lightmapscale (int)**: The light map resolution on the face.
- **smoothing_groups (int)**: Select a smoothing group to use for lighting on the face.
- **contents (bitfield)**
- **flags (bitfield)**
- **dispinfo{}**
- **vertices_plus{}**

### 7.1 Planes

A plane is a fundamental two-dimensional object. It can be visualized as a flat infinite sheet of paper positioned in a three-dimensional world.

```plaintext
"plane" "(0 0 0) (0 0 0) (0 0 0)"
```

- **plane ((vertex) (vertex) (vertex))**: Defines the three points that set the plane's orientation and position in the three-dimensional world.

### 7.2 U/V Axis

The u-axis and v-axis are the texture specific axes.

```plaintext
"uaxis" "[1 0 0 0] 0.25"
"vaxis" "[0 1 0 0] 0.25"
```

- **u/v axis ([x y z dec] dec)**: The x, y, and z are decimal values representing that axis. The following value is the translation and the last decimal is the total

 scaling.

### 7.3 Dispinfo

The dispinfo Class deals with all the information for a displacement map.

```plaintext
dispinfo
{
      "power" "2"
      "startposition" "[-512 -512 0]"
      "elevation" "0"
      "subdiv" "0"
      normals{}
      distances{}
      offsets{}
      offset_normals{}
      alphas{}
      triangle_tags{}
      allowed_verts{}
}
```

- **power (2,3,4)**: Used to calculate the number of rows and columns.
- **startposition (vertex)**
- **elevation (float)**
- **subdiv (bool)**
- **normals{}**
- **distances{}**
- **offsets{}**
- **offset_normals{}**
- **alphas{}**
- **triangle_tags{}**
- **allowed_verts{}**

#### 7.3.1 Normals

This Class defines the normal line for each vertex.

```plaintext
normals
{
      "row0" "X0 Y0 Z0 X1 Y1 Z1 X2 Y2 Z2 X3 Y3 Z3 X4 Y4 Z4"
      "row1" "0 0 0 0 0 0 0 0 0 0 0 0 0 0 0"
      "row2" "0 0 1 0 0 1 0 0 1 0 0 1 0 0 1"
      "row3" "0 0 -1 0 0 -1 0 0 -1 0 0 -1 0 0 -1"
}
```

#### 7.3.2 Distances

The distance values represent how much the vertex is moved along the normal line.

```plaintext
distances
{
      "row0" "#0 #1 #2 #3 #4"
      "row1" "0 0 0 0 0 "
      "row2" "64 64 64 64 64"
      "row3" "64 64 64 64 64"
      "row4" "32 32 32 32 32"
}
```

#### 7.3.3 Offsets

This Class lists all the default positions for each vertex in a displacement map.

```plaintext
offsets
{
      "row0" "X0 Y0 Z0 X1 Y1 Z1 X2 Y2 Z2 X3 Y3 Z3 X4 Y4 Z4"
      "row1" "0 0 0 0 0 0 0 0 0 0 0 0 0 0 0"
      "row2" "0 0 64 0 0 64 0 0 64 0 0 64 0 0 64"
      "row3" "0 0 -64 0 0 -64 0 0 -64 0 0 -64 0 0 -64"
      "row4" "0 0 32 0 0 -32 0 0 -32 0 0 -32 0 0 32"
}
```

#### 7.3.4 offset_normals

This Class is almost identical to the normals Class but defines the default normal lines.

#### 7.3.5 Alpha

This Class contains a value for each vertex representing how much of which texture is shown in blended materials.

```plaintext
alphas
{
      "row0" "#0 #1 #2 #3 #4"
      "row1" "0 0 0 0 0 "
      "row2" "255 255 255 255 255"
      "row3" "0 0 0 0 0"
      "row4" "128 0 0 0 128"
}
```

#### 7.3.6 triangle_tags

This Class contains information specific to each triangle in the displacement.

```plaintext
triangle_tags
{
      "row0" "#0 #0 #1 #1 #2 #2 #3 #3 #4 #4"
      "row1" "9 9 9 9 9 9 9 9"
      "row2" "0 0 0 0 0 0 0 0"
      "row3" "1 1 1 1 1 1 1 1"
      "row4" "9 9 9 9 9 9 9 9"
}
```

#### 7.3.7 allowed_verts

This affects the in-game tessellation of the displacement map.

```plaintext
allowed_verts
{
      "10" "-1 -1 -1 -1 -1 -1 -1 -1 -1 -1"
}
```

## 8. Editor

All information within this Class is for Hammer only and bears no significance to the map itself.

```plaintext
editor
{
      "color" "0 255 0"
      "visgroupid" "2"
      "groupid" "7"
      "visgroupshown" "1"
      "visgroupautoshown" "1"
      "comments" "Only exists on entities."
      "logicalpos" "[34 28]"
}
```

## 9. Group

This class sets the brush groups that exist and their properties.

```plaintext
group
{
      "id" "7"
      editor{}
}
```

## 10. Hidden

There are two versions of the hidden class, but both include classes which have the visgroupshown or autovisgroupshown in editor set to "0".

### 10.1 First Type

```plaintext
hidden
{
      solid{}
}
solid{}
```

### 10.2 Second Type

```plaintext
hidden
{
      entity{}
}
entity{}
```

## 11. Entity

Both brush and point-based entities are defined in the same way.

```plaintext
entity
{
      "id" "19"
      "classname" "func_detail"
      "spawnflags" "0"
      ______
      connections{}
      solid{}
      hidden{}
      "origin" "-512 0 0"
      editor{}
}
```

### 11.1 Connections

This is where all the outputs for an entity are stored.

```plaintext
connections
{
      "OnTrigger" "bob,Color,255 255 0,1.23,1"
      "OnTrigger" "bob,ToggleSprite,,3.14,-1"
}
```

## 12. Cameras

Used for the 3D viewport cameras used in Hammer, created with the Camera Tool.

```plaintext
cameras
{
    "activecamera" "1"
    camera
    {
        "position" "[-1093.7 1844.91 408.455]"
        "look" "[-853.42 1937.5 175.863]"
    }
    camera
    {
        "position" "[692.788 1394.95 339.652]"
        "look" "[508.378 1493 347.127]"
    }
    camera
    {
        "position" "[-4613.89 2528.77 -2834.88]"
        "look" "[-4533.53 2950.1 -2896.85]"
    }
}
cameras
{
    "activecamera" "-1"
}
```

## 13. Cordon

This stores all the information Hammer needs and uses for its cordon tool.

```plaintext
cordon
{
      "mins" "(99999 99999 99999)"
      "maxs" "(-99999 -99999 -99999)"
      "active" "0"
}
```

### 13.1 Newer Hammer Versions (L4D onwards)

```plaintext
cordons
{
	"active" "0"
	cordon
	{
		"name" "cordon"
		"active" "1"
		box
		{
			"mins" "(-1204 -1512 -748)"
			"maxs" "(836 444 1128)"
		}
	}
}
```

## 14. Hammer++ Additions

Hammer++ introduces several new entries to the VMF format, enhancing functionality and customization.

### 14.1 Instances

Instances are a way to reuse parts of maps. They are defined in the VMF using the `instance` class.

```plaintext
instance
{
      "id" "123"
      "classname" "func_instance"
      "file" "instances/my_instance.vmf"
      "origin" "0 0 0"
      editor{}
}
```

- **id (int)**: A unique identifier for the instance.
- **classname (sz)**: The class name, typically "func_instance".
- **file (sz)**: The path to the instance VMF file.
- **origin (vertex)**: The position of the instance in the main map.

### 14.2 Instance Parameters

Instance parameters allow for customization of instances.

```plaintext
instance_parameters
{
      "parameter_name" "value"
      ...
}
```

- **parameter_name (sz)**: The name of the parameter.
- **value (sz)**: The value assigned to the parameter.

### 14.3 Custom Visgroups

Hammer++ allows for more complex visibility grouping with custom visgroups.

```plaintext
custom_visgroups
{
      "group_name" "value"
      ...
}
```

- **group_name (sz)**: The name

 of the custom visgroup.
- **value (sz)**: The value associated with the custom visgroup.

### 14.4 vertices_plus

The `vertices_plus` keyvalue allows for the explicit definition of vertices for each side of a brush.

```plaintext
vertices_plus
{
      "v" "-387.528 -192 70"
      "v" "-448 -192 70"
      "v" "-448 -192 64"
      "v" "-387.528 -192 64"
}
```

### 14.5 palette_plus

The `palette_plus` keyvalue allows for the definition of custom color palettes.

```plaintext
palette_plus
{
	"color0" "255 255 255"
	"color1" "255 255 255"
	"color2" "255 255 255"
	"color3" "255 255 255"
	"color4" "255 255 255"
	"color5" "255 255 255"
	"color6" "255 255 255"
	"color7" "255 255 255"
	"color8" "255 255 255"
	"color9" "255 255 255"
	"color10" "255 255 255"
	"color11" "255 255 255"
	"color12" "255 255 255"
	"color13" "255 255 255"
	"color14" "255 255 255"
	"color15" "255 255 255"
}
```

### 14.6 colorcorrection_plus

The `colorcorrection_plus` keyvalue allows for advanced color correction settings.

```plaintext
colorcorrection_plus
{
	"name0" ""
	"weight0" "1"
	"name1" ""
	"weight1" "1"
	"name2" ""
	"weight2" "1"
	"name3" ""
	"weight3" "1"
}
```

### 14.7 light_plus

The `light_plus` keyvalue provides additional lighting options for the map.

```plaintext
light_plus
{
	"samples_sun" "6"
	"samples_ambient" "40"
	"samples_vis" "256"
	"texlight" ""
	"incremental_delay" "0"
	"bake_dist" "1024"
	"radius_scale" "1"
	"brightness_scale" "1"
	"ao_scale" "0"
	"bounced" "1"
	"incremental" "1"
	"supersample" "0"
	"bleed_hack" "1"
	"soften_cosine" "0"
	"debug" "0"
	"cubemap" "1"
	"hdr" "0"
}
```

### 14.8 bgimages_plus

The `bgimages_plus` keyvalue allows for the inclusion of background images.

```plaintext
bgimages_plus
{
}
```
