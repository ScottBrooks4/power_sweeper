public static class AppModel
{
    public static string Describe(string root)
    {
        var cfg = Path.Combine(root, "server", "config.json");
        return File.Exists(cfg) ? File.ReadAllText(cfg) : "{}";
    }
}
