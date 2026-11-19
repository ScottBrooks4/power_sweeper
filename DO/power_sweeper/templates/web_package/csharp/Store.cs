using System.Text.Json;
using System.Xml.Linq;
using Microsoft.Data.Sqlite;

public static class AppStore
{
    public static Dictionary<string, object?> Dispatch(string root, JsonElement body)
    {
        var action = body.TryGetProperty("action", out var actionEl) ? actionEl.GetString() ?? "list" : "list";
        var table = body.TryGetProperty("table", out var tableEl) ? tableEl.GetString() ?? "Items" : "Items";
        if (string.Equals(StorageOf(root), "sql", StringComparison.OrdinalIgnoreCase))
        {
            return Sql(root, action, table, body);
        }
        return Xml(root, action, table, body);
    }

    private static Dictionary<string, object?> Xml(string root, string action, string table, JsonElement body)
    {
        var path = Path.Combine(root, "data", "app.xml");
        Directory.CreateDirectory(Path.GetDirectoryName(path)!);
        if (!File.Exists(path))
        {
            File.WriteAllText(path, "<app>\n</app>\n");
        }
        var doc = XDocument.Load(path);
        var rootEl = doc.Root ?? new XElement("app");
        if (doc.Root == null)
        {
            doc.Add(rootEl);
        }
        var name = Safe(table);
        var parent = rootEl.Elements().FirstOrDefault(e => e.Name.LocalName == name);
        if (action == "list")
        {
            var rows = parent == null ? new List<Dictionary<string, string>>() : parent.Elements("row").Select(RowFromXml).ToList();
            return new Dictionary<string, object?> { ["ok"] = true, ["rows"] = rows, ["table"] = name };
        }
        if (action == "insert")
        {
            parent ??= new XElement(name);
            if (parent.Parent == null)
            {
                rootEl.Add(parent);
            }
            var row = RowFromBody(body);
            if (!row.ContainsKey("Id") || row["Id"].Length == 0)
            {
                row["Id"] = (parent.Elements("row").Count() + 1).ToString();
            }
            var el = new XElement("row");
            foreach (var pair in row)
            {
                el.SetAttributeValue(Safe(pair.Key), pair.Value);
            }
            if (row.TryGetValue("Title", out var title))
            {
                el.Value = title;
            }
            parent.Add(el);
            doc.Save(path);
            return new Dictionary<string, object?> { ["ok"] = true, ["row"] = row, ["table"] = name };
        }
        return new Dictionary<string, object?> { ["ok"] = false, ["error"] = "unknown action" };
    }

    private static Dictionary<string, object?> Sql(string root, string action, string table, JsonElement body)
    {
        var db = Path.Combine(root, "data", "app.sqlite");
        var schema = Path.Combine(root, "data", "schema.sql");
        Directory.CreateDirectory(Path.GetDirectoryName(db)!);
        using var conn = new SqliteConnection($"Data Source={db}");
        conn.Open();
        if (File.Exists(schema))
        {
            using var check = conn.CreateCommand();
            check.CommandText = "SELECT count(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'";
            var tables = Convert.ToInt32(check.ExecuteScalar());
            if (tables == 0)
            {
                var sql = File.ReadAllText(schema);
                foreach (var stmt in sql.Split(';', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries))
                {
                    if (stmt.StartsWith("--", StringComparison.Ordinal) && !stmt.Contains('\n'))
                    {
                        continue;
                    }
                    using var cmd = conn.CreateCommand();
                    cmd.CommandText = stmt;
                    cmd.ExecuteNonQuery();
                }
            }
        }
        var name = Safe(table);
        if (action == "list")
        {
            using var cmd = conn.CreateCommand();
            cmd.CommandText = "SELECT * FROM " + name;
            using var reader = cmd.ExecuteReader();
            var rows = new List<Dictionary<string, string>>();
            while (reader.Read())
            {
                var row = new Dictionary<string, string>();
                for (var i = 0; i < reader.FieldCount; i++)
                {
                    row[reader.GetName(i)] = reader.IsDBNull(i) ? "" : reader.GetValue(i)?.ToString() ?? "";
                }
                rows.Add(row);
            }
            return new Dictionary<string, object?> { ["ok"] = true, ["rows"] = rows, ["table"] = name };
        }
        if (action == "insert")
        {
            var row = RowFromBody(body);
            if (!row.ContainsKey("Id"))
            {
                row["Id"] = "";
            }
            var cols = row.Keys.Select(Safe).ToList();
            using var cmd = conn.CreateCommand();
            cmd.CommandText = "INSERT INTO " + name + " (" + string.Join(",", cols) + ") VALUES ("
                + string.Join(",", cols.Select(c => "@" + c)) + ")";
            foreach (var pair in row)
            {
                cmd.Parameters.AddWithValue("@" + Safe(pair.Key), pair.Value);
            }
            cmd.ExecuteNonQuery();
            return new Dictionary<string, object?> { ["ok"] = true, ["row"] = row, ["table"] = name };
        }
        return new Dictionary<string, object?> { ["ok"] = false, ["error"] = "unknown action" };
    }

    private static Dictionary<string, string> RowFromBody(JsonElement body)
    {
        var row = new Dictionary<string, string>();
        if (!body.TryGetProperty("row", out var raw) || raw.ValueKind != JsonValueKind.Object)
        {
            return row;
        }
        foreach (var prop in raw.EnumerateObject())
        {
            row[prop.Name] = prop.Value.ValueKind == JsonValueKind.String
                ? prop.Value.GetString() ?? ""
                : prop.Value.ToString();
        }
        return row;
    }

    private static Dictionary<string, string> RowFromXml(XElement el)
    {
        var row = new Dictionary<string, string>();
        foreach (var attr in el.Attributes())
        {
            row[attr.Name.LocalName] = attr.Value;
        }
        var text = el.Value.Trim();
        if (text.Length > 0 && !row.ContainsKey("Title"))
        {
            row["Title"] = text;
        }
        return row;
    }

    private static string StorageOf(string root)
    {
        var path = Path.Combine(root, "server", "config.json");
        if (!File.Exists(path))
        {
            return "xml";
        }
        using var doc = JsonDocument.Parse(File.ReadAllText(path));
        return doc.RootElement.TryGetProperty("storage", out var el) ? el.GetString() ?? "xml" : "xml";
    }

    public static string Safe(string name)
    {
        var cleaned = System.Text.RegularExpressions.Regex.Replace(name ?? "", @"\W+", "_");
        return cleaned.Length == 0 ? "Items" : cleaned;
    }
}
